<?php

namespace App\Security;

use App\Api\ApiErrorResponseFactory;
use App\Repository\TreeMergeDraftRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final class TreeMergeReadAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(private readonly TreeMergeReadGrant $grants, private readonly TreeMergeDraftRepository $drafts,
        private readonly ApiErrorResponseFactory $errors)
    {
    }

    public function supports(Request $request): bool
    {
        return '/mcp/read' === $request->getPathInfo() && str_starts_with($request->headers->get('Authorization', ''), 'Merge ');
    }

    public function authenticate(Request $request): SelfValidatingPassport
    {
        $grant = $this->grants->consume(substr($request->headers->get('Authorization', ''), 6));
        $draft = $this->drafts->find($grant['draftId']);
        if (null === $draft || $draft->attemptId !== $grant['attemptId'] || 'processing' !== $draft->state
            || $draft->owner->getId()->toRfc4122() !== $grant['userId'] || $draft->tree->getId()->toRfc4122() !== $grant['treeId']) {
            throw new CustomUserMessageAuthenticationException('invalid_merge_access');
        }
        $request->attributes->set(TreeMergeReadGrant::class, $grant);

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
