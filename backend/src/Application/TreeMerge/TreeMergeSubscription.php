<?php

namespace App\Application\TreeMerge;

use App\Api\ApiException;
use App\Entity\AuthSession;
use App\Entity\TreeMergeDraft;
use App\Repository\AuthSessionRepository;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Uid\Uuid;

final readonly class TreeMergeSubscription
{
    /** @param non-empty-string $secret
     * @param non-empty-string $issuer
     */
    public function __construct(private Authorization $authorization, private TreeMergeEvents $events, private ClockInterface $clock,
        private AuthSessionRepository $sessions,
        #[Autowire(env: 'MERCURE_JWT_SECRET')] private string $secret,
        #[Autowire(env: 'MERCURE_JWT_ISSUER')] private string $issuer)
    {
    }

    /** Rechecked by Caddy before opening a subscriber connection. The hub also validates its grants. */
    public function authorize(Request $request): void
    {
        try {
            $config = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText($this->secret));
            $value = $request->cookies->get('__Secure-mercure_access_token', '');
            if ('' === $value) {
                throw new \RuntimeException('Missing subscription.');
            }
            $token = $config->parser()->parse($value);
            if (!$token instanceof UnencryptedToken || !$token->claims()->has('exp')
                || !$config->validator()->validate($token, new SignedWith($config->signer(), $config->verificationKey()), new IssuedBy($this->issuer), new LooseValidAt($this->clock))) {
                throw new \RuntimeException('Invalid subscription.');
            }
            $id = $token->claims()->get('sub');
            $session = is_string($id) && Uuid::isValid($id) ? $this->sessions->find($id) : null;
            if (null !== $session && $session->isRefreshActive($this->clock->now())) {
                return;
            }
        } catch (\Throwable) {
            // Credentials and parser diagnostics never enter API errors or logs.
        }
        throw new ApiException(401, 'AUTH_REQUIRED');
    }

    public function create(Request $request, TreeMergeDraft $draft): JsonResponse
    {
        $session = $request->attributes->get(AuthSession::class);
        if (!$session instanceof AuthSession || !$session->isAccessActive($this->clock->now())) {
            throw new ApiException(401, 'AUTH_REQUIRED');
        }
        $prefix = $this->events->sessionTopic($session);
        $expires = $this->clock->now()->modify('+5 minutes');
        $cookie = $this->authorization->createCookie($request, ['urlpattern' => [$prefix . '*']], additionalClaims: [
            'exp' => $expires, 'sub' => $session->getId()->toRfc4122(),
        ])->withDomain(null);
        $response = new JsonResponse([
            'url'       => '/.well-known/mercure?match=' . rawurlencode($prefix . $draft->id->toRfc4122()),
            'expiresIn' => 300,
        ], headers: ['Cache-Control' => 'no-store']);
        $response->headers->setCookie($cookie);

        return $response;
    }
}
