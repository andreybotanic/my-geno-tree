<?php

namespace App\Application\OAuth;

use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

#[AsDecorator('league.oauth2_server.repository.access_token')]
final readonly class ResourceTokenRepository implements AccessTokenRepositoryInterface
{
    public function __construct(
        #[AutowireDecorated] private AccessTokenRepositoryInterface $inner,
        private OAuthMetadata $metadata,
    ) {
    }

    public function getNewToken(
        ClientEntityInterface $clientEntity,
        array $scopes,
        ?string $userIdentifier = null,
    ): ResourceAccessToken {
        $token = new ResourceAccessToken($this->metadata->issuer(), $this->metadata->resource());
        $token->setClient($clientEntity);
        if (null !== $userIdentifier && '' !== $userIdentifier) {
            $token->setUserIdentifier($userIdentifier);
        }
        foreach ($scopes as $scope) {
            $token->addScope($scope);
        }

        return $token;
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        $this->inner->persistNewAccessToken($accessTokenEntity);
    }

    public function revokeAccessToken(string $tokenId): void
    {
        $this->inner->revokeAccessToken($tokenId);
    }

    public function isAccessTokenRevoked(string $tokenId): bool
    {
        return $this->inner->isAccessTokenRevoked($tokenId);
    }
}
