<?php

namespace App\EventListener;

use Mcp\Schema\JsonRpc\Error;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
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
        $status = $error instanceof HttpExceptionInterface
            ? $error->getStatusCode()
            : Response::HTTP_INTERNAL_SERVER_ERROR;
        $serverError = $status >= Response::HTTP_INTERNAL_SERVER_ERROR;
        if ($serverError) {
            $this->logger->error('Unhandled MCP exception.', ['exception' => $error]);
        }
        $event->setResponse(
            new JsonResponse(
                [
                    'jsonrpc' => '2.0',
                    'id'      => null,
                    // JSON-RPC 2.0 distinguishes an invalid request from an internal server failure.
                    'error' => [
                        'code'    => $serverError ? Error::INTERNAL_ERROR : Error::INVALID_REQUEST,
                        'message' => $serverError ? 'Internal error' : 'Invalid request',
                    ],
                ],
                $status,
            ),
        );
    }
}
