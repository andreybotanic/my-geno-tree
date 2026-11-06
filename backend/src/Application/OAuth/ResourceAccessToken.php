<?php

namespace App\Application\OAuth;

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\AccessTokenTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class ResourceAccessToken implements AccessTokenEntityInterface
{
    use AccessTokenTrait;
    use EntityTrait;
    use TokenEntityTrait;

    /** @param non-empty-string $issuer
     * @param non-empty-string $resource
     */
    public function __construct(private readonly string $issuer, private readonly string $resource)
    {
    }

    public function toString(): string
    {
        $contents = $this->privateKey->getKeyContents();
        if ('' === $contents) {
            throw new \LogicException('OAuth signing key is empty.');
        }
        $configuration = Configuration::forAsymmetricSigner(
            new Sha256(),
            InMemory::plainText($contents, $this->privateKey->getPassPhrase() ?? ''),
            InMemory::plainText('unused'),
        );

        return $configuration->builder()
            ->issuedBy($this->issuer)
            ->permittedFor($this->resource)
            ->identifiedBy($this->getIdentifier())
            ->issuedAt(new \DateTimeImmutable())
            ->canOnlyBeUsedAfter(new \DateTimeImmutable())
            ->expiresAt($this->getExpiryDateTime())
            ->relatedTo($this->getUserIdentifier() ?? $this->getClient()->getIdentifier())
            ->withClaim('client_id', $this->getClient()->getIdentifier())
            ->withClaim('scopes', $this->getScopes())
            ->getToken($configuration->signer(), $configuration->signingKey())
            ->toString();
    }
}
