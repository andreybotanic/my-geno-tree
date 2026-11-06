<?php

namespace App\EventListener;

use App\Api\ApiErrorResponseFactory;
use App\Api\ApiException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 128)]
final readonly class ApiExceptionListener
{
    public function __construct(
        private ApiErrorResponseFactory $responseFactory,
        private LoggerInterface $logger,
        private Security $security,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/v1')
            || 'mcp' === $this->security->getFirewallConfig($event->getRequest())?->getName()) {
            return;
        }

        $throwable = $event->getThrowable();
        if ($throwable instanceof ApiException) {
            $event->setResponse($this->responseFactory->create($throwable));

            return;
        }

        if (
            $throwable instanceof AuthenticationException
            || $throwable instanceof AccessDeniedException
        ) {
            $event->setResponse($this->responseFactory->unauthorized());

            return;
        }

        if ($throwable instanceof HttpExceptionInterface) {
            $status = $throwable->getStatusCode();
            $code = match ($status) {
                JsonResponse::HTTP_NOT_FOUND         => 'NOT_FOUND',
                JsonResponse::HTTP_UNAUTHORIZED      => 'UNAUTHORIZED',
                JsonResponse::HTTP_FORBIDDEN         => 'FORBIDDEN',
                JsonResponse::HTTP_TOO_MANY_REQUESTS => 'RATE_LIMIT_EXCEEDED',
                default                              => 'HTTP_ERROR',
            };
            $response = $this->responseFactory->create(new ApiException(
                $status,
                $code,
                strtolower($code),
            ));
            foreach ($throwable->getHeaders() as $name => $value) {
                $response->headers->set($name, $value);
            }
            $event->setResponse($response);

            return;
        }

        $this->logger->error('Unhandled API exception.', [
            'exception' => $throwable,
        ]);
        $event->setResponse($this->responseFactory->create(new ApiException(
            JsonResponse::HTTP_INTERNAL_SERVER_ERROR,
            'INTERNAL_ERROR',
            'internal_error',
        )));
    }
}
