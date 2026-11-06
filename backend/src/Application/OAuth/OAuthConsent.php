<?php

namespace App\Application\OAuth;

use App\Api\ApiException;
use App\Entity\AuthSession;
use League\Bundle\OAuth2ServerBundle\Converter\UserConverterInterface;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Uid\Uuid;

/** One-time consent bound to a signed-in site session; League validates and completes the OAuth grant. */
final readonly class OAuthConsent
{
    public function __construct(
        private AuthorizationServer $server,
        private OAuthClientRegistration $clients,
        private OAuthMetadata $metadata,
        private UserConverterInterface $users,
        #[Target('cache.oauth_authorization')]
        private CacheItemPoolInterface $cache,
        private LockFactory $locks,
    ) {
    }

    /** @param array<string, mixed> $parameters
     * @return array{id: string, clientName: string, redirectUri: string, scope: string}
     */
    public function requestConsent(array $parameters, AuthSession $session): array
    {
        if (($parameters['resource'] ?? null) !== $this->metadata->resource()
            || 'S256' !== ($parameters['code_challenge_method'] ?? null)
            || !is_string($parameters['client_id'] ?? null)
            || !is_string($parameters['redirect_uri'] ?? null)
            || !is_string($parameters['code_challenge'] ?? null)
            || 1 !== preg_match('/^[A-Za-z0-9_-]{43}$/D', $parameters['code_challenge'])
            || 'mcp' !== ($parameters['scope'] ?? 'mcp')
            || 'code' !== ($parameters['response_type'] ?? null)
            || (isset($parameters['state'])
                && (!is_string($parameters['state']) || strlen($parameters['state']) > 4096))) {
            throw OAuthServerException::invalidRequest('authorization_parameters');
        }
        $client = $this->clients->resolve($parameters['client_id']);
        $request = new ServerRequest('GET', $this->metadata->issuer());
        $validated = $this->server->validateAuthorizationRequest($request->withQueryParams($parameters));
        $id = Uuid::v7()->toRfc4122();
        $item = $this->cache->getItem($id);
        $item->set(['session' => $session->getId()->toRfc4122(), 'parameters' => $parameters]);
        $item->expiresAfter(300);
        $this->cache->save($item);

        return [
            'id'          => $id,
            'clientName'  => $client->getName(),
            'redirectUri' => (string)$validated->getRedirectUri(),
            'scope'       => 'mcp',
        ];
    }

    public function completeAuthorization(string $id, bool $approved, AuthSession $session): string
    {
        if (!Uuid::isValid($id)) {
            throw new ApiException(404, 'OAUTH_AUTHORIZATION_NOT_FOUND');
        }
        $lock = $this->locks->createLock('oauth-consent:' . $id);
        $lock->acquire(true);
        try {
            $item = $this->cache->getItem($id);
            $saved = $item->get();
            if (!$item->isHit() || !is_array($saved)
                || ($saved['session'] ?? null) !== $session->getId()->toRfc4122()
                || !is_array($saved['parameters'] ?? null)) {
                throw new ApiException(404, 'OAUTH_AUTHORIZATION_NOT_FOUND');
            }
            try {
                $request = $this->server->validateAuthorizationRequest(
                    new ServerRequest('GET', $this->metadata->issuer())->withQueryParams($saved['parameters']),
                );
            } catch (OAuthServerException) {
                $this->cache->deleteItem($id);
                throw new ApiException(409, 'OAUTH_AUTHORIZATION_INVALID');
            }
            $request->setUser($this->users->toLeague($session->getUser()));
            $request->setAuthorizationApproved($approved);
            $this->cache->deleteItem($id);
            try {
                $response = $this->server->completeAuthorizationRequest($request, new Response());
            } catch (OAuthServerException $error) {
                $response = $error->generateHttpResponse(new Response());
            }
            $redirect = $response->getHeaderLine('Location');

            return $redirect . (str_contains($redirect, '?') ? '&' : '?')
                . http_build_query(['iss' => $this->metadata->issuer()]);
        } finally {
            $lock->release();
        }
    }
}
