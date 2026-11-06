<?php

namespace App\EventListener;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\Uid\Uuid;

final readonly class McpHttpListener
{
    public function __construct(#[Target('cache.mcp_identity')] private CacheItemPoolInterface $sessions)
    {
    }

    #[AsEventListener(priority: 300)]
    public function preflight(RequestEvent $event): void
    {
        $path = $event->getRequest()->getPathInfo();
        if ('OPTIONS' === $event->getRequest()->getMethod()
            && ('/api/v1/mcp' === $path || $this->publicOAuthPath($path))) {
            $event->setResponse(
                new Response(
                    status: 204,
                    headers: [
                        'Access-Control-Allow-Origin'  => '*',
                        'Access-Control-Allow-Methods' => 'GET, POST, DELETE, OPTIONS',
                        'Access-Control-Allow-Headers' => 'Authorization, Content-Type, Accept, '
                            . 'MCP-Protocol-Version, Mcp-Session-Id, Mcp-Method, Mcp-Name',
                    ],
                ),
            );
        }
    }

    #[AsEventListener(priority: 0)]
    public function checkSession(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if ('/api/v1/mcp' !== $request->getPathInfo() || !$request->headers->has('Mcp-Session-Id')) {
            return;
        }
        $id = $request->headers->get('Mcp-Session-Id', '');
        $identity = $request->attributes->get('mcp_identity');
        if (!Uuid::isValid($id) || !is_string($identity) || $identity !== $this->sessions->getItem($id)->get()) {
            $event->setResponse(
                new JsonResponse(
                    [
                        'jsonrpc' => '2.0',
                        'id'      => null,
                        'error'   => ['code' => -32001, 'message' => 'MCP session not found'],
                    ],
                    404,
                ),
            );
        }
    }

    #[AsEventListener]
    public function response(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();
        if ($this->publicOAuthPath($request->getPathInfo())) {
            $response->headers->set('Access-Control-Allow-Origin', '*');
        }
        if ('/api/v1/mcp' !== $request->getPathInfo() || !$response->isSuccessful()) {
            return;
        }
        $id = $response->headers->get('Mcp-Session-Id', $request->headers->get('Mcp-Session-Id', ''));
        $identity = $request->attributes->get('mcp_identity');
        if (Uuid::isValid($id) && is_string($identity)) {
            $item = $this->sessions->getItem($id);
            $item->set($identity);
            $item->expiresAfter(3600);
            $this->sessions->save($item);
        }
    }

    private function publicOAuthPath(string $path): bool
    {
        return 1 === preg_match(
            '~^/(?:api/v1/oauth/(?:register|token|revoke)|\.well-known/oauth-(?:authorization-server|protected-resource(?:/api/v1/mcp)?))$~D',
            $path,
        );
    }
}
