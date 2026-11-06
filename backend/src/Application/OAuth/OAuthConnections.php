<?php

namespace App\Application\OAuth;

use App\Entity\OAuthClient;
use Defuse\Crypto\Crypto;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Controller\TokenController;
use League\Bundle\OAuth2ServerBundle\Model\AccessToken;
use League\Bundle\OAuth2ServerBundle\Model\AuthorizationCode;
use League\Bundle\OAuth2ServerBundle\Model\RefreshToken;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\ResourceServer;
use Nyholm\Psr7\ServerRequest;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Add atomic token rotation and per-user connection revocation to League's standard token endpoint. */
final readonly class OAuthConnections
{
    public function __construct(
        private TokenController $tokens,
        private ResourceServer $resourceServer,
        private OAuthMetadata $metadata,
        private ClientRepositoryInterface $clients,
        private EntityManagerInterface $entityManager,
        #[Autowire('%env(file:resolve:OAUTH_ENCRYPTION_KEY)%')]
        private string $encryptionKey,
    ) {
    }

    public function issueTokens(Request $request): HttpResponse
    {
        $parameters = $request->request->all();
        // Legacy clients may omit resource; ResourceAccessToken always restricts the audience to MCP.
        if (array_key_exists('resource', $parameters) && $parameters['resource'] !== $this->metadata->resource()) {
            throw OAuthServerException::invalidRequest('resource');
        }
        $id = $parameters['client_id'] ?? urldecode($request->getUser() ?? '');
        if (!is_string($id)) {
            throw OAuthServerException::invalidRequest('client_id');
        }

        return $this->entityManager->wrapInTransaction(
            function () use ($request, $parameters, $id): HttpResponse {
                $this->entityManager->find(OAuthClient::class, $id, LockMode::PESSIMISTIC_WRITE);
                $response = $this->tokens->indexAction($request);
                $error = json_decode((string)$response->getContent(), true);
                if (400 === $response->getStatusCode() && 'refresh_token' === ($parameters['grant_type'] ?? null)
                    && is_array($error) && 'invalid_grant' === ($error['error'] ?? null)) {
                    $payload = $this->refreshPayload($parameters['refresh_token'] ?? null);
                    $refresh = is_string($payload['refresh_token_id'] ?? null)
                        ? $this->entityManager->find(RefreshToken::class, $payload['refresh_token_id'])
                        : null;
                    if (null !== $refresh && $refresh->isRevoked() && ($payload['client_id'] ?? null) === $id
                        && is_int($payload['expire_time'] ?? null)
                        && $payload['expire_time'] >= time()) {
                        $this->revokeConnection($id, $refresh->getAccessToken()?->getUserIdentifier());
                    }
                }

                return $response;
            },
        );
    }

    public function revokeTokens(Request $request): HttpResponse
    {
        $parameters = $request->request->all();
        $id = $parameters['client_id'] ?? '';
        $secret = $parameters['client_secret'] ?? null;
        if (str_starts_with($request->headers->get('Authorization', ''), 'Basic ')) {
            [$id, $secret] = array_pad(
                explode(':', (string)base64_decode(substr($request->headers->get('Authorization', ''), 6), true), 2),
                2,
                '',
            );
            $id = urldecode($id);
            $secret = urldecode($secret);
        }
        if (!is_string($id) || (null !== $secret && !is_string($secret))) {
            throw OAuthServerException::invalidRequest('client_id');
        }
        if (!$this->clients->validateClient($id, $secret, null)) {
            throw OAuthServerException::invalidClient(new ServerRequest('POST', $this->metadata->issuer()));
        }

        return $this->entityManager->wrapInTransaction(
            function () use ($parameters, $id): HttpResponse {
                $this->entityManager->find(OAuthClient::class, $id, LockMode::PESSIMISTIC_WRITE);
                $access = $this->accessForRevocation($parameters['token'] ?? '', $id);
                if (null !== $access) {
                    $this->revokeConnection($id, $access->getUserIdentifier());
                }

                return new HttpResponse(status: 200, headers: ['Cache-Control' => 'no-store']);
            },
        );
    }

    private function accessForRevocation(mixed $token, string $clientId): ?AccessToken
    {
        if (!is_string($token)) {
            return null;
        }
        $payload = $this->refreshPayload($token);
        $id = ($payload['client_id'] ?? null) === $clientId ? ($payload['access_token_id'] ?? null) : null;
        if (null === $id) {
            try {
                $validated = $this->resourceServer->validateAuthenticatedRequest(
                    new ServerRequest('POST', $this->metadata->resource(), ['Authorization' => 'Bearer ' . $token]),
                );
                $id = $validated->getAttribute('oauth_access_token_id');
            } catch (OAuthServerException) {
                return null;
            }
        }
        $access = is_string($id) ? $this->entityManager->find(AccessToken::class, $id) : null;

        return null !== $access && $access->getClient()->getIdentifier() === $clientId ? $access : null;
    }

    /** @return array<string, mixed> */
    private function refreshPayload(mixed $token): array
    {
        if (!is_string($token) || strlen($token) > 16384) {
            return [];
        }
        try {
            $payload = json_decode(
                Crypto::decryptWithPassword($token, $this->encryptionKey),
                true,
                16,
                JSON_THROW_ON_ERROR,
            );

            return is_array($payload) ? $payload : [];
        } catch (\Exception) {
            return [];
        }
    }

    private function revokeConnection(string $clientId, ?string $userId): void
    {
        if (null === $userId) {
            return;
        }
        $accessTokens = $this->entityManager->createQueryBuilder()
            ->select('access.identifier')
            ->from(AccessToken::class, 'access')
            ->where('access.client = :client AND access.userIdentifier = :user')
            ->getDQL();
        foreach ([AuthorizationCode::class, AccessToken::class, RefreshToken::class] as $class) {
            $this->entityManager->createQueryBuilder()
                ->update($class, 'token')
                ->set('token.revoked', 'true')
                ->where(
                    RefreshToken::class === $class
                        ? 'token.accessToken IN (' . $accessTokens . ')'
                        : 'token.client = :client AND token.userIdentifier = :user',
                )
                ->setParameter(
                    'client',
                    $clientId,
                )
                ->setParameter(
                    'user',
                    $userId,
                )
                ->getQuery()
                ->execute();
        }
    }
}
