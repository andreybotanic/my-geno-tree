<?php

namespace App\Security;

use App\Api\ApiErrorResponseFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/** Transport identity permits discovery only; every tool separately resolves its user's chat. */
final class TreeMergeAgentAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(
        #[Autowire(env: 'TREE_MERGE_MCP_KEY')] private readonly string $serviceKey,
        private readonly ApiErrorResponseFactory $errors,
    ) {
    }

    public function supports(Request $request): bool
    {
        return '/mcp/agent' === $request->getPathInfo() && $request->headers->has('Authorization');
    }

    public function authenticate(Request $request): SelfValidatingPassport
    {
        if ('' === $this->serviceKey || !hash_equals('Bearer ' . $this->serviceKey, $request->headers->get('Authorization', ''))) {
            throw new CustomUserMessageAuthenticationException('invalid_agent_service');
        }

        return new SelfValidatingPassport(new UserBadge('tree-agent', static fn () => new InMemoryUser('tree-agent', null, ['ROLE_MCP_AGENT'])));
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
