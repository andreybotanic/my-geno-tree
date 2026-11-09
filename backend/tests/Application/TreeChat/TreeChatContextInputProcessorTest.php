<?php

namespace App\Tests\Application\TreeChat;

use App\Application\TreeChat\TreeChatContextInputProcessor;
use App\Model\Conversation;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Input;
use Symfony\AI\Agent\InputProcessor\SystemPromptInputProcessor;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

final class TreeChatContextInputProcessorTest extends TestCase
{
    public function testCurrentRagRulesReplaceStoredPromptWithoutLosingMessagesOrDuplicatingContext(): void
    {
        $requests = new RequestStack();
        $request = new Request();
        $request->attributes->set(
            Conversation::class,
            new Conversation(
                'tree:test',
                'tree',
                ['treeId' => Uuid::v7()->toRfc4122()],
                Uuid::v7(),
                Uuid::v7(),
                new \DateTimeImmutable(),
            )
        );
        $requests->push($request);
        $processor = new TreeChatContextInputProcessor($requests, new SystemPromptInputProcessor('Current RAG rules.'));
        $input = new Input('fixture', new MessageBag(Message::forSystem('Legacy prompt.'), Message::ofUser('Find a letter.')));
        $processor->processInput($input);
        $processor->processInput($input);
        $system = $input->getMessageBag()->getSystemMessage()?->getContent();
        self::assertIsString($system);
        self::assertStringContainsString('Current RAG rules.', $system);
        self::assertStringNotContainsString('Legacy prompt.', $system);
        self::assertSame(1, substr_count($system, 'Tree context:'));
        self::assertCount(1, $input->getMessageBag()->withoutSystemMessage());
    }
}
