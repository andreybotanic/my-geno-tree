<?php

namespace App\Security;

use App\Application\OAuth\OAuthMetadata;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use League\OAuth2\Server\ResourceServer;
use Nyholm\Psr7\ServerRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final readonly class McpTokenHandler implements
    AccessTokenHandlerInterface,
    AuthenticationEntryPointInterface,
    AuthenticationFailureHandlerInterface
{
    public function __construct(
        private ResourceServer $server,
        private OAuthMetadata $metadata,
        private RequestStack $requests,
    ) {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        try {
            if ('' === $accessToken) {
                throw new BadCredentialsException();
            }
            $request = $this->server->validateAuthenticatedRequest(
                new ServerRequest('POST', $this->metadata->resource(), ['Authorization' => 'Bearer ' . $accessToken]),
            );
            $token = new Parser(new JoseEncoder())->parse($accessToken);
            $id = $request->getAttribute('oauth_user_id');
            $scopes = $request->getAttribute('oauth_scopes', []);
            $clientId = $token instanceof UnencryptedToken ? $token->claims()->get('client_id') : null;
            if (!$token instanceof UnencryptedToken || !$token->isPermittedFor($this->metadata->resource())
                || !$token->hasBeenIssuedBy($this->metadata->issuer())
                || !is_array($scopes)
                || !in_array('mcp', $scopes, true)
                || !is_string($id)
                || '' === $id
                || !is_string($clientId)
                || '' === $clientId) {
                throw new BadCredentialsException();
            }
            $this->requests->getCurrentRequest()?->attributes->set(
                'mcp_identity',
                hash('sha256', $id . ':' . $clientId),
            );

            return new UserBadge($id);
        } catch (\Exception $error) {
            throw new BadCredentialsException('invalid_token', previous: $error);
        }
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new JsonResponse(
            ['error' => 'invalid_token'],
            401,
            ['WWW-Authenticate' => $this->metadata->challenge(), 'Cache-Control' => 'no-store'],
        );
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return $this->start($request, $exception);
    }
}
