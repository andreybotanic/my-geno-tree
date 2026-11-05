<?php

namespace App\Application\TreeMerge\Ai;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/** Adds only the current attempt's credential to the bundle's MCP transport. */
final readonly class MergeMcpHttpClient implements ClientInterface
{
    public function __construct(
        #[Autowire(service: 'psr18.http_client')] private ClientInterface $client,
        private RequestStack $requests,
        #[Autowire(env: 'TREE_MERGE_MCP_URL')] private string $endpoint,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $token = $this->requests->getCurrentRequest()?->attributes->get(self::class);
        if (!is_string($token) || '' === $token || (string)$request->getUri() !== $this->endpoint) {
            throw new \LogicException('MERGE_MCP_SCOPE_REQUIRED');
        }

        return $this->client->sendRequest($request->withHeader('Authorization', 'Merge ' . $token));
    }
}
