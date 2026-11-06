<?php

namespace App\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/** Render MCP transport failures without intercepting the firewall's OAuth challenge. */
#[AsEventListener(priority: 128)]
final readonly class McpExceptionListener
{
    public function __construct(private Security $security, private LoggerInterface $logger)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $error = $event->getThrowable();
        if ('mcp' !== $this->security->getFirewallConfig($event->getRequest())?->getName()
            || $error instanceof AuthenticationException || $error instanceof AccessDeniedException) {
            return;
        }
        $status = $error instanceof HttpExceptionInterface ? $error->getStatusCode() : 500;
        if ($status >= 500) {
            $this->logger->error('Unhandled MCP exception.', ['exception' => $error]);
        }
        $event->setResponse(
            new JsonResponse(
                [
                    'jsonrpc' => '2.0',
                    'id'      => null,
                    'error'   => ['code' => $status >= 500 ? -32603 : -32600, 'message' => $status >= 500 ? 'Internal error' : 'Invalid request'],
                ],
                $status,
            ),
        );
    }
}
