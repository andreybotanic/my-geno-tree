<?php

namespace App\Security;

use App\Api\ApiErrorResponseFactory;
use App\Entity\AuthSession;
use App\Repository\AuthSessionRepository;
use App\Repository\TreeMergeDraftRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final class TreeMergeAgentAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(private readonly TreeMergeAgentGrant $grants, private readonly TreeMergeDraftRepository $drafts,
        private readonly ApiErrorResponseFactory $errors, private readonly AuthSessionRepository $sessions, private readonly ClockInterface $clock)
    {
    }

    public function supports(Request $request): bool
    {
        return '/mcp/agent' === $request->getPathInfo() && str_starts_with($request->headers->get('Authorization', ''), 'Merge ');
    }

    public function authenticate(Request $request): SelfValidatingPassport
    {
        $grant = $this->grants->consume(substr($request->headers->get('Authorization', ''), 6));
        $session = $this->sessions->find($grant['sessionId']);
        if (null === $session || !$session->isRefreshActive($this->clock->now()) || $session->getUser()->getId()->toRfc4122() !== $grant['userId']) {
            throw new CustomUserMessageAuthenticationException('invalid_merge_session');
        }
        // Restore the initiating login session before Doctrine hydrates ephemeral chat messages.
        $request->attributes->set(AuthSession::class, $session);
        $draft = $this->drafts->find($grant['draftId']);
        if (null === $draft || $draft->attemptId !== $grant['attemptId'] || $draft->version !== $grant['version'] || 'processing' !== $draft->state
            || $draft->owner->getId()->toRfc4122() !== $grant['userId'] || $draft->tree->getId()->toRfc4122() !== $grant['treeId']) {
            throw new CustomUserMessageAuthenticationException('invalid_merge_access');
        }
        $request->attributes->set(TreeMergeAgentGrant::class, $grant);

        return new SelfValidatingPassport(new UserBadge($draft->owner->getUserIdentifier(), fn () => $draft->owner));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return $this->errors->unauthorized();
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->errors->unauthorized();
    }
}
