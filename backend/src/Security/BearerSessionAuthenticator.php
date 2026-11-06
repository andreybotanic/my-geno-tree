<?php

namespace App\Security;

use App\Api\ApiErrorResponseFactory;
use App\Entity\AuthSession;
use App\Repository\AuthSessionRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final class BearerSessionAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface, AuthenticationFailureHandlerInterface
{
    public function __construct(
        private readonly AuthSessionRepository $sessions,
        private readonly ClockInterface $clock,
        private readonly ApiErrorResponseFactory $responseFactory,
    ) {
    }

    public function supports(Request $request): bool
    {
        return str_starts_with($request->headers->get('Authorization', ''), 'Bearer ');
    }

    public function authenticate(Request $request): SelfValidatingPassport
    {
        $plainToken = substr($request->headers->get('Authorization', ''), 7);
        $session = '' === $plainToken
            ? null
            : $this->sessions->findOneByAccessToken($plainToken);

        if (null === $session || !$session->isAccessActive($this->clock->now())) {
            throw new CustomUserMessageAuthenticationException('invalid_session');
        }

        $request->attributes->set(AuthSession::class, $session);

        return new SelfValidatingPassport(
            new UserBadge(
                $session->getUser()->getUserIdentifier(),
                $session->getUser(...),
            )
        );
    }

    public function onAuthenticationSuccess(
        Request $request,
        TokenInterface $token,
        string $firewallName,
    ): ?Response {
        return null;
    }

    public function onAuthenticationFailure(
        Request $request,
        AuthenticationException $exception,
    ): Response {
        return $this->responseFactory->unauthorized();
    }

    public function start(
        Request $request,
        ?AuthenticationException $authException = null,
    ): Response {
        return $this->responseFactory->unauthorized();
    }
}
