<?php

namespace App\Application\TreeMerge\Ai;

use App\Application\TreeMerge\MergeActivity;
use App\Entity\TreeMergeDraft;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** Stop only this merge's inference; unrelated application HTTP calls remain independent. */
#[AsDecorator('merge_ai.client')]
final class CancellableMergeHttpClient implements HttpClientInterface
{
    use DecoratorTrait;

    public function __construct(#[AutowireDecorated] HttpClientInterface $client,
        private readonly MergeActivity $activity, private readonly RequestStack $requests)
    {
        $this->client = $client;
    }

    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $draft = $this->requests->getCurrentRequest()?->attributes->get(TreeMergeDraft::class);
        if ($draft instanceof TreeMergeDraft) {
            $this->activity->assertRunning($draft);
            $progress = $options['on_progress'] ?? null;
            $options['on_progress'] = function (int $downloaded, int $size, array $info) use ($draft, $progress): void {
                $this->activity->assertRunning($draft);
                if (is_callable($progress)) {
                    $progress($downloaded, $size, $info);
                }
            };
        }

        return $this->client->request($method, $url, $options);
    }
}
