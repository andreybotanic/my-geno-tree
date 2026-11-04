<?php

namespace App\Application\TreeMerge;

use App\Api\ApiException;
use App\Application\Tree\TreeVersion;
use App\Application\TreeMerge\Ai\Dto\MergeAction;
use App\Application\TreeMerge\Ai\MergeAiProposalMapper;
use App\Application\TreeMerge\Ai\MergeAiRepair;
use App\Application\TreeMerge\Ai\MergeAiResult;
use App\Application\TreeMerge\Ai\MergeReplySummary;
use App\Application\TreeMerge\Ai\TreeMergeAiInterface;
use App\Entity\TreeMergeDraft;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/** @phpstan-import-type ContextDate from MergeAiResult */
final readonly class TreeMergeConversationService
{
    public const int MAX_REPAIRS = 2;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TreeMergeAiInterface $ai,
        private MergeAiProposalMapper $mapper,
        private TreeMergeProposalValidator $validator,
        private TreeVersion $versions,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private MergeActivity $activity,
        #[Target('tree_merge')] private RateLimiterFactoryInterface $limiter,
        private RequestStack $requests,
        private MergeReplySummary $summary,
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
                if ('assistant' === $message['role'] && $requestId === $message['requestId'] && !isset($message['failureCode'])) {
                    return null;
                }
            }
            $last = array_find(array_reverse($draft->messages), static fn ($message) => 'user' === $message['role']);
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
        $this->requests->getCurrentRequest()?->attributes->set(TreeMergeDraft::class, $draft);
        $repair = null;
        for ($number = 0; $number <= self::MAX_REPAIRS + 1; ++$number) {
            $questionsOnly = $repair->questionsOnly ?? false;
            $step = match (true) {
                $questionsOnly => 'clarifying',
                0 === $number  => 'thinking',
                default        => 'repairing',
            };
            $this->activity->step($draft, $step);
            $failure = 'provider_unavailable';
            // Every model call, including repairs, runs outside ORM transactions and tree locks.
            try {
                $this->activity->assertRunning($draft);
                $answer = $this->ai->respond($draft, $attempt, $repair);
                $this->activity->step($draft, 'validating');
            } catch (\Throwable $error) {
                $this->logger->warning('Tree merge inference failed: ' . $error::class);
                if ($error instanceof ApiException && 'TREE_MERGE_TOKEN_LIMIT' === $error->getErrorCode()) {
                    $failure = 'token_limit';
                }
                $answer = null;
            }
            $repair = $this->entityManager->wrapInTransaction(function () use ($draft, $attempt, $attemptVersion, $requestId, $answer, $number, $failure, $questionsOnly, $repair): ?MergeAiRepair {
                $this->entityManager->lock($draft->tree, LockMode::PESSIMISTIC_WRITE);
                $this->entityManager->refresh($draft->tree);
                $this->entityManager->lock($draft, LockMode::PESSIMISTIC_WRITE);
                $this->entityManager->refresh($draft);
                if ($draft->attemptId !== $attempt || $draft->version !== $attemptVersion || 'processing' !== $draft->state) {
                    return null;
                }
                if (null === $answer) {
                    $this->fail($draft, $requestId, $failure, $repair);

                    return null;
                }
                try {
                    if ($questionsOnly && (MergeAction::CLARIFICATION !== $answer->response->action || null !== $answer->response->proposal)) {
                        throw new ApiException(422, 'TREE_MERGE_CLARIFICATION_INVALID');
                    }
                    if ($number > 0 && !in_array($answer->response->action, [MergeAction::PROPOSAL, MergeAction::CLARIFICATION], true)) {
                        throw new ApiException(422, 'TREE_MERGE_REPAIR_ACTION_INVALID', fields: ['action' => ['expected_proposal_or_clarification']]);
                    }
                    $this->complete($draft, $requestId, $answer);
                } catch (\Throwable $error) {
                    $code = $error instanceof ApiException ? $error->getErrorCode() : $error::class;
                    $fields = $error instanceof ApiException ? implode(',', array_keys($error->getFields())) : '';
                    $this->logger->warning('Tree merge response rejected: ' . $code . ' ' . $fields, ['generation' => $number + 1]);
                    if ($error instanceof ApiException && 422 === $error->getStatus()
                        && ($number > 0 || in_array($answer->response->action, [MergeAction::PROPOSAL, MergeAction::CLARIFICATION], true))
                        && $number <= self::MAX_REPAIRS) {
                        return new MergeAiRepair($answer, $error, self::MAX_REPAIRS === $number);
                    }
                    $this->fail($draft, $requestId, 'validation_failed', $repair);
                }

                return null;
            });
            if (null === $repair) {
                return;
            }
        }
    }

    public function stop(TreeMergeDraft $draft, mixed $requestId): void
    {
        if (!is_string($requestId) || '' === $requestId || strlen($requestId) > 100) {
            throw new ApiException(422, 'TREE_MERGE_INVALID');
        }
        $this->entityManager->wrapInTransaction(function () use ($draft, $requestId): void {
            $this->entityManager->lock($draft, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($draft);
            $last = array_find(array_reverse($draft->messages), static fn ($message) => 'user' === $message['role']);
            if (null === $last || $last['requestId'] !== $requestId || in_array($draft->state, ['applied', 'cancelled'], true)) {
                throw new ApiException(409, 'TREE_MERGE_VERSION_CONFLICT');
            }
            if (array_any($draft->messages, static fn ($message) => 'assistant' === $message['role'] && $message['requestId'] === $requestId && !isset($message['failureCode']))) {
                return;
            }
            $this->activity->stop($draft, $requestId);
            $draft->stop($requestId, $this->clock->now());
        });
    }

    private function fail(TreeMergeDraft $draft, string $requestId, string $code, ?MergeAiRepair $repair = null): void
    {
        $russian = 'ru' === $draft->owner->getPreferredLanguage();
        if (null !== $repair && 'token_limit' !== $code) {
            $draft->ask($requestId, [$repair->fallbackQuestion($russian)], $this->clock->now());

            return;
        }
        if ('token_limit' === $code) {
            $reply = $russian ? 'Достигнут дневной лимит токенов. Продолжите позже. Ваше предложение доступно для проверки.'
                : 'The daily token limit has been reached. Please continue later. Your proposal remains available for review.';
        } elseif ('validation_failed' === $code) {
            $reply = $russian ? 'Не удалось подготовить корректное предложение после нескольких попыток. Ваш текст сохранён. Уточните, кто кому приходится родственником, или попробуйте ещё раз.'
                : 'I could not prepare a valid proposal after several attempts. Your text is saved. Please clarify the relationships or try again.';
        } else {
            $reply = $russian ? 'Не удалось получить ответ. Ваш текст сохранён. Попробуйте ещё раз чуть позже.'
                : 'I could not get a response. Your text is saved. Please try again shortly.';
        }
        $draft->processingFailed($this->clock->now(), $requestId, $reply, $code);
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
            if (MergeAction::CLARIFICATION === $response->action && null === $response->proposal) {
                if ([] === $response->questions || count($response->questions) > 3 || array_any($response->questions,
                    static fn ($question) => !is_string($question) || '' === trim($question) || mb_strlen($question) > 1000 || !str_contains($question, '?'))) {
                    throw new ApiException(422, 'TREE_MERGE_CLARIFICATION_INVALID');
                }
                $summary = $this->summary->describe($draft, $response, null);
                $draft->ask($requestId, $response->questions, $this->clock->now(), $summary);

                return;
            }
            if (null === $response->proposal) {
                throw new ApiException(422, 'TREE_MERGE_AI_INVALID', fields: ['proposal' => ['proposal_required']]);
            }
            $proposal = $this->mapper->map($draft, $response->proposal, $result->people);
            $validated = $this->validator->validate($draft, $proposal);
            $summary = $this->summary->describe($draft, $response, $validated->proposal);
            $draft->markSource($requestId);
            $state = match (true) {
                [] !== $validated->questions => 'questions',
                $validated->hasChanges       => 'ready',
                default                      => 'no_changes',
            };
            $draft->propose($validated->proposal, $validated->questions, $this->versions->etag($draft->tree), $state, false, $this->clock->now());
            $reply = $russian ? 'Подготовил изменения. Проверьте их на дереве.' : 'The changes are ready to review on the tree.';
            if ('no_changes' === $state) {
                $reply = $russian ? 'Эти сведения уже есть в дереве.' : 'This information is already in the tree.';
            } elseif ([] !== $validated->questions) {
                $reply = '';
                foreach (array_slice($validated->questions, 0, 3) as $question) {
                    if (str_starts_with($question, 'person_match:')) {
                        $id = substr($question, 13);
                        /** @var list<array{id: string, fields: array{givenName?: array{value?: string}}}> $people */
                        $people = $validated->proposal['people'];
                        $person = array_find($people, static fn ($person) => $person['id'] === $id);
                        $name = $person['fields']['givenName']['value'] ?? ($russian ? 'этот человек' : 'this person');
                        $question = $russian ? 'Кто ' . $name . ': человек из дерева или новая персона?' : 'Is ' . $name . ' someone in the tree or a new person?';
                    }
                    $reply .= ('' === $reply ? '' : "\n") . $question;
                }
            }
            $reply = $summary . "\n" . $reply;
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
