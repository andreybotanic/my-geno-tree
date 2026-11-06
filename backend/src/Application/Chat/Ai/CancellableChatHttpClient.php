<?php

namespace App\Application\Chat\Ai;

use App\Application\Chat\GuardedMessageStore;
use App\Security\ChatExecution;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** Cancels the active chat inference when its request has been stopped. */
#[AsDecorator('chat_ai.client')]
final class CancellableChatHttpClient implements HttpClientInterface
{
    use DecoratorTrait;

    public function __construct(
        #[AutowireDecorated]
        HttpClientInterface $client,
        private readonly GuardedMessageStore $store,
        private readonly RequestStack $requests,
    ) {
        $this->client = $client;
    }

    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $execution = $this->requests->getMainRequest()?->attributes->get(ChatExecution::class);
        if ($execution instanceof ChatExecution) {
            $this->store->assertRunning($execution);
            $progress = $options['on_progress'] ?? null;
            $options['on_progress'] = function (
                int $downloaded,
                int $size,
                array $info,
            ) use ($execution, $progress): void {
                $this->store->assertRunning($execution);
                if (is_callable($progress)) {
                    $progress($downloaded, $size, $info);
                }
            };
        }

        return $this->client->request($method, $url, $options);
    }
}
