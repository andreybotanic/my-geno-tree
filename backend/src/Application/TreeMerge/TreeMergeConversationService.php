<?php

namespace App\Application\TreeMerge;

use App\Api\ApiException;
use App\Application\Tree\TreeVersion;
use App\Application\TreeMerge\Ai\Dto\MergeAction;
use App\Application\TreeMerge\Ai\MergeAiProposalMapper;
use App\Application\TreeMerge\Ai\MergeAiResult;
use App\Application\TreeMerge\Ai\TreeMergeAiInterface;
use App\Entity\TreeMergeDraft;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/** @phpstan-import-type ContextDate from MergeAiResult */
final readonly class TreeMergeConversationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TreeMergeAiInterface $ai,
        private MergeAiProposalMapper $mapper,
        private TreeMergeProposalValidator $validator,
        private TreeVersion $versions,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        #[Target('tree_merge')] private RateLimiterFactoryInterface $limiter,
    ) {
    }

    public function process(TreeMergeDraft $draft, mixed $version, mixed $requestId): void
    {
        if (!is_int($version) || !is_string($requestId)) {
            throw new ApiException(422, 'TREE_MERGE_INVALID');
        }
        $attempt = $this->entityManager->wrapInTransaction(function () use ($draft, $version, $requestId): ?string {
            $this->entityManager->lock($draft, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($draft);
            foreach ($draft->messages as $message) {
                if ('assistant' === $message['role'] && $requestId === $message['requestId']) {
                    return null;
                }
            }
            $last = $draft->messages[array_key_last($draft->messages) ?? 0] ?? null;
            if ($draft->version !== $version || null === $last || $last['requestId'] !== $requestId
                || 'user' !== $last['role'] || in_array($draft->state, ['applied', 'cancelled'], true)) {
                throw new ApiException(409, 'TREE_MERGE_VERSION_CONFLICT');
            }
            if ('processing' === $draft->state && $draft->attemptStartedAt > $this->clock->now()->modify('-5 minutes')) {
                throw new ApiException(409, 'TREE_MERGE_PROCESSING');
            }
            if (!$this->limiter->create($draft->owner->getId()->toRfc4122())->consume()->isAccepted()) {
                throw new ApiException(429, 'TREE_MERGE_RATE_LIMIT');
            }

            return $draft->beginAttempt($this->clock->now());
        });
        if (null === $attempt) {
            return;
        }
        $attemptVersion = $draft->version;
        // No ORM transaction or tree lock is held during inference or HTTP MCP calls.
        try {
            $answer = $this->ai->respond($draft, $attempt);
        } catch (\Throwable $error) {
            $this->logger->warning('Tree merge inference failed: ' . $error::class);
            $answer = null;
        }
        $this->entityManager->wrapInTransaction(function () use ($draft, $attempt, $attemptVersion, $requestId, $answer): void {
            $this->entityManager->lock($draft->tree, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($draft->tree);
            $this->entityManager->lock($draft, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($draft);
            if ($draft->attemptId !== $attempt || $draft->version !== $attemptVersion || 'processing' !== $draft->state) {
                return;
            }
            try {
                if (null === $answer) {
                    throw new \RuntimeException('TREE_MERGE_AI_UNAVAILABLE');
                }
                $this->complete($draft, $requestId, $answer);
            } catch (\Throwable $error) {
                $code = $error instanceof ApiException ? $error->getErrorCode() : $error::class;
                $fields = $error instanceof ApiException ? implode(',', array_keys($error->getFields())) : '';
                $this->logger->warning('Tree merge response rejected: ' . $code . ' ' . $fields);
                $draft->processingFailed($this->clock->now());
            }
        });
    }

    private function complete(TreeMergeDraft $draft, string $requestId, MergeAiResult $result): void
    {
        $response = $result->response;
        $russian = 'ru' === $draft->owner->getPreferredLanguage();
        if (MergeAction::REFUSAL === $response->action) {
            $reply = $russian ? 'Я помогаю только с выбранным семейным деревом. Вернёмся к сведениям о ваших родственниках.'
                : 'I can only help with your selected family tree. Let us return to your relatives.';
        } elseif (MergeAction::TREE_ANSWER === $response->action) {
            // Render only observed tree facts, never free-form model assertions.
            $reply = $russian ? 'Подтверждённые сведения дерева:' : 'Confirmed tree information:';
            foreach (array_slice(array_unique($response->answerPersonIds), 0, 5) as $id) {
                $person = $result->people[$id] ?? throw new ApiException(422, 'TREE_MERGE_UNOBSERVED_PERSON');
                $reply .= "\n" . implode(' ', array_filter([$person['givenName']['value'] ?? null,
                    $person['patronymic']['value'] ?? null, $person['birthSurname']['value'] ?? null]));
                $reply .= ' · ' . ($russian ? 'рождение: ' : 'birth: ') . $this->date($person['birthDate'], $russian);
                $life = $russian ? 'жив(а)' : 'alive';
                if (null !== $person['deathDate']) {
                    $life = ($russian ? 'смерть: ' : 'death: ') . $this->date($person['deathDate'], $russian);
                }
                $reply .= ' · ' . $life;
            }
            if ([] === $response->answerPersonIds) {
                $reply = $russian ? 'Уточните, о ком в дереве вы спрашиваете. Для применения изменений используйте отдельную кнопку подтверждения.'
                    : 'Please specify the person in your tree. To apply changes, use the separate confirmation button.';
            }
        } else {
            if (null === $response->proposal) {
                throw new ApiException(422, 'TREE_MERGE_AI_INVALID');
            }
            $proposal = $this->mapper->map($draft, $response->proposal, $result->people);
            $validated = $this->validator->validate($draft, $proposal);
            $draft->markSource($requestId);
            $state = match (true) {
                [] !== $validated->questions => 'questions',
                $validated->hasChanges       => 'ready',
                default                      => 'no_changes',
            };
            $draft->propose($validated->proposal, $validated->questions, $this->versions->etag($draft->tree), $state, false, $this->clock->now());
            $reply = $russian ? 'Проверьте предложенные изменения и уточнения в предпросмотре.' : 'Review the proposed changes and questions in the preview.';
        }
        $draft->answer($requestId, $reply, $this->clock->now());
    }

    /** @param ContextDate $date */
    private function date(array $date, bool $russian): string
    {
        if ('unknown' === $date['state']) {
            return $russian ? 'неизвестно' : 'unknown';
        }
        $prefix = match ($date['kind'] ?? 'exact') {
            'approximate' => '~ ', 'before' => '< ', 'after' => '> ', default => '',
        };
        $text = $prefix . implode('-', array_filter([$date['year'] ?? null, $date['month'] ?? null, $date['day'] ?? null]));

        return isset($date['end']) ? $text . ' — ' . implode('-', array_filter($date['end'])) : $text;
    }
}
