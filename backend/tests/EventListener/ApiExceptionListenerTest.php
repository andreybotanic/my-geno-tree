<?php

namespace App\Tests\EventListener;

use App\Api\ApiErrorResponseFactory;
use App\Api\ApiException;
use App\EventListener\ApiExceptionListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class ApiExceptionListenerTest extends TestCase
{
    public function testNonApiExceptionsAreIgnored(): void
    {
        $event = $this->event('/profile', new \RuntimeException());
        $listener = new ApiExceptionListener(
            new ApiErrorResponseFactory(),
            self::createStub(LoggerInterface::class),
        );

        $listener($event);

        self::assertFalse($event->hasResponse());
    }

    public function testApiExceptionIsRenderedDirectly(): void
    {
        $event = $this->event('/api/v1/test', new ApiException(
            409,
            'CONFLICT',
            'conflict',
        ));
        $listener = new ApiExceptionListener(
            new ApiErrorResponseFactory(),
            self::createStub(LoggerInterface::class),
        );

        $listener($event);

        self::assertSame(409, $event->getResponse()?->getStatusCode());
        self::assertSame('CONFLICT', $this->responseCode($event));
    }

    public function testSecurityExceptionUsesTheUnifiedUnauthorizedResponse(): void
    {
        $event = $this->event(
            '/api/v1/test',
            new AccessDeniedException(),
        );
        $listener = new ApiExceptionListener(
            new ApiErrorResponseFactory(),
            self::createStub(LoggerInterface::class),
        );

        $listener($event);

        self::assertSame(401, $event->getResponse()?->getStatusCode());
        self::assertSame('UNAUTHORIZED', $this->responseCode($event));
    }

    /**
     * @param class-string<\Throwable> $exceptionClass
     */
    #[DataProvider('httpExceptionProvider')]
    public function testHttpExceptionsKeepTheirStatusAndMachineCode(
        string $exceptionClass,
        int $status,
        string $code,
    ): void {
        $event = $this->event('/api/v1/test', new $exceptionClass());
        $listener = new ApiExceptionListener(
            new ApiErrorResponseFactory(),
            self::createStub(LoggerInterface::class),
        );

        $listener($event);

        self::assertSame($status, $event->getResponse()?->getStatusCode());
        self::assertSame($code, $this->responseCode($event));
    }

    /**
     * @return iterable<string, array{
     *     class-string<\Throwable>,
     *     int,
     *     string
     * }>
     */
    public static function httpExceptionProvider(): iterable
    {
        yield 'not found' => [NotFoundHttpException::class, 404, 'NOT_FOUND'];
        yield 'forbidden' => [
            AccessDeniedHttpException::class,
            403,
            'FORBIDDEN',
        ];
    }

    public function testUnexpectedExceptionIsLoggedAndHidden(): void
    {
        $throwable = new \RuntimeException('sensitive');
        $event = $this->event('/api/v1/test', $throwable);
        $logger = self::createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('error')
            ->with('Unhandled API exception.', ['exception' => $throwable]);
        $listener = new ApiExceptionListener(
            new ApiErrorResponseFactory(),
            $logger,
        );

        $listener($event);

        self::assertSame(500, $event->getResponse()?->getStatusCode());
        self::assertSame('INTERNAL_ERROR', $this->responseCode($event));
        self::assertStringNotContainsString(
            'sensitive',
            (string)$event->getResponse()->getContent(),
        );
    }

    private function event(
        string $path,
        \Throwable $throwable,
    ): ExceptionEvent {
        return new ExceptionEvent(
            self::createStub(KernelInterface::class),
            Request::create($path),
            1,
            $throwable,
        );
    }

    private function responseCode(ExceptionEvent $event): string
    {
        $response = $event->getResponse();
        self::assertNotNull($response);
        $content = $response->getContent();
        self::assertIsString($content);
        $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsString($data['code']);

        return $data['code'];
    }
}
