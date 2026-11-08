<?php

namespace App\Application\TreeChat;

use App\Entity\AuthSession;
use App\Model\Conversation;
use Symfony\AI\Agent\Attribute\AsInputProcessor;
use Symfony\AI\Agent\Input;
use Symfony\AI\Agent\InputProcessorInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\HttpFoundation\RequestStack;

#[AsInputProcessor(agent: 'ai.agent.tree', priority: -100)]
final readonly class TreeChatContextInputProcessor implements InputProcessorInterface
{
    public const string VERSION = 'tree-v20-atomic-parents';

    public function __construct(private RequestStack $requests)
    {
    }

    public function processInput(Input $input): void
    {
        $draft = $this->requests->getCurrentRequest()?->attributes->get(Conversation::class);
        if (!$draft instanceof Conversation) {
            return;
        }
        $session = $this->requests->getMainRequest()?->attributes->get(AuthSession::class);
        $context = json_encode(
            [
                'promptVersion'     => self::VERSION,
                'interfaceLanguage' => $session instanceof AuthSession
                    ? $session->getUser()->getPreferredLanguage()
                    : 'en',
                'treeId'                     => $draft->context['treeId'] ?? null,
                'narrativeReferencePersonId' => $draft->context['referencePersonId'] ?? null,
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );
        $messages = $input->getMessageBag();
        $system = $messages->getSystemMessage()?->getContent() ?? '';
        $prepared = MessageBag::forId($messages->getId());
        foreach ($messages->withoutSystemMessage() as $message) {
            if ($message instanceof \Symfony\AI\Platform\Message\AssistantMessage
                && '' === trim($message->asText() ?? '') && !$message->hasToolCalls()) {
                continue;
            }
            $prepared = $prepared->with($message);
        }
        $input->setMessageBag(
            $prepared->withSystemMessage(Message::forSystem($system . "\n\nTree context:\n" . $context)),
        );
    }
}
