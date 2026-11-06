<?php

namespace App\Http;

use App\Application\Chat\ChatInterface;
use App\Model\Conversation;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsTargetedValueResolver;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

#[AsTargetedValueResolver]
final readonly class ChatValueResolver implements ValueResolverInterface
{
    public function __construct(private ChatInterface $chat)
    {
    }

    /** @return iterable<Conversation> */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        yield $this->chat->get($request->attributes->getString('chatId'));
    }
}
