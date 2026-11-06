<?php

namespace App\Application\Chat\Moderation;

use App\Api\ApiException;
use App\Application\Chat\GuardedMessageStore;
use App\Repository\SuspiciousChatMessageRepository;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Attribute\AsInputProcessor;
use Symfony\AI\Agent\Input;
use Symfony\AI\Agent\InputProcessorInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsInputProcessor(agent: 'ai.agent.tree', priority: 500)]
final readonly class ModerationInputProcessor implements InputProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'ai.agent.chat_moderation')] private AgentInterface $agent,
        private ChatModeration $moderation,
        private GuardedMessageStore $store,
        private SuspiciousChatMessageRepository $audit,
    ) {
    }

    public function processInput(Input $input): void
    {
        $execution = $this->store->execution();
        $messages = $input->getMessageBag();
        $message = array_find(
            array_reverse($messages->getMessages()),
            static fn ($message) => $message instanceof UserMessage,
        );
        if (!$message instanceof UserMessage) {
            throw new \LogicException('Missing user message for moderation.');
        }
        $key = hash('sha256', implode(':', [
            $execution->conversation->userId->toRfc4122(),
            $execution->conversation->id->toRfc4122(),
            $execution->conversation->attemptRequestId,
        ]));
        $saved = $this->audit->findOperation($key);
        $risk = $message->getMetadata()->get('moderationRisk');
        if (null !== $saved) {
            $number = $saved->getWarningNumber();
            $risk = $saved->getRisk();
        } else {
            if (!is_float($risk)) {
                try {
                    $risk = $this->agent
                        ->call(
                            new MessageBag(Message::ofUser($message->asText() ?? '')),
                            ['response_format' => ModerationAssessment::class],
                        )
                        ->asObject(ModerationAssessment::class)
                        ->risk;
                } catch (ApiException $error) {
                    throw $error;
                } catch (\Throwable $error) {
                    $this->moderation->unavailable();
                    throw new ApiException(503, 'CHAT_UNAVAILABLE', previous: $error);
                }
            }
            $this->store->execution();
            $number = $this->moderation->recordAssessment(
                $execution->conversation->userId,
                $key,
                $message->asText() ?? '',
                $risk,
            );
        }
        $message->getMetadata()->add('moderationRisk', $risk);
        $message->getMetadata()->add('moderationAllowed', 0 === $number);
        $this->store->save($messages);
        if ($number > 0) {
            throw new ModerationWarning($number);
        }
        $prepared = MessageBag::forId($messages->getId());
        foreach ($messages as $item) {
            if (($item instanceof UserMessage && false === $item->getMetadata()->get('moderationAllowed'))
                || ($item instanceof SystemMessage && $item->getMetadata()->has('failureCode'))) {
                continue;
            }
            $prepared = $prepared->with($item);
        }
        $prepared->getMetadata()->set($messages->getMetadata()->all());
        $input->setMessageBag($prepared);
    }
}
