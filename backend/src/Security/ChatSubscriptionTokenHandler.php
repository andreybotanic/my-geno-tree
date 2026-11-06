<?php

namespace App\Security;

use App\Repository\AuthSessionRepository;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Uid\Uuid;

/** Validates the Mercure credential presented to Symfony's access_token firewall. */
final readonly class ChatSubscriptionTokenHandler implements AccessTokenHandlerInterface
{
    /** @param non-empty-string $secret
     * @param non-empty-string $issuer
     * @param non-empty-string $audience
     */
    public function __construct(
        private AuthSessionRepository $sessions,
        private ClockInterface $clock,
        #[Autowire(env: 'MERCURE_JWT_SECRET')]
        private string $secret,
        #[Autowire(env: 'MERCURE_JWT_ISSUER')]
        private string $issuer,
        #[Autowire(env: 'MERCURE_PUBLIC_URL')]
        private string $audience,
    ) {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        try {
            $config = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText($this->secret));
            $token = '' === $accessToken ? null : $config->parser()->parse($accessToken);
            if ($token instanceof UnencryptedToken && $token->claims()->has('exp')
                && $config
                    ->validator()
                    ->validate(
                        $token,
                        new SignedWith($config->signer(), $config->verificationKey()),
                        new IssuedBy($this->issuer),
                        new PermittedFor($this->audience),
                        new LooseValidAt($this->clock),
                    )) {
                $id = $token->claims()->get('sub');
                $session = is_string($id) && Uuid::isValid($id) ? $this->sessions->find($id) : null;
                if (null !== $session && $session->isRefreshActive($this->clock->now())) {
                    return new UserBadge($session->getUser()->getUserIdentifier(), $session->getUser(...));
                }
            }
        } catch (\Throwable) {
            // Keep credentials and parser diagnostics out of authentication errors.
        }

        throw new BadCredentialsException('Invalid chat subscription.');
    }
}
