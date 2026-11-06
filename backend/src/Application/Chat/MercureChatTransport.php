<?php

namespace App\Application\Chat;

use App\Api\ApiException;
use App\Entity\AuthSession;
use App\Model\Conversation;
use App\Repository\AuthSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

/** Private, ephemeral events use the same login-session boundary as conversation memory. */
final readonly class MercureChatTransport
{
    /** @param non-empty-string $secret
     * @param non-empty-string $issuer
     */
    public function __construct(
        #[Target('defaultHub')] private HubInterface $hub,
        private RequestStack $requests,
        private LoggerInterface $logger,
        #[Autowire('%env(MERCURE_JWT_ISSUER)%')] private string $topicOrigin,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private Authorization $authorization,
        private AuthSessionRepository $sessions,
        #[Autowire(env: 'MERCURE_JWT_SECRET')] private string $secret,
        #[Autowire(env: 'MERCURE_JWT_ISSUER')] private string $issuer,
    ) {
    }

    public function sessionTopic(AuthSession $session): string
    {
        return $this->topicOrigin . '/api/v1/chat-events/sessions/' . $session->getId()->toRfc4122() . '/conversations/';
    }

    public function progress(Conversation $conversation): void
    {
        $session = $this->requests->getMainRequest()?->attributes->get(AuthSession::class);
        if ($session instanceof AuthSession) {
            $this->publish($session, $this->sessionTopic($session) . $conversation->id->toRfc4122(), [
                'kind'             => 'progress',
                'id'               => $conversation->id->toRfc4122(),
                'version'          => $conversation->version,
                'attemptId'        => $conversation->attemptId,
                'attemptStartedAt' => $conversation->attemptStartedAt?->format(\DateTimeInterface::ATOM),
                'progress'         => $conversation->progress,
                'tokenUsage'       => $conversation->usage,
            ]);
        }
    }

    /** @param array<string, mixed> $conversation */
    public function conversation(AuthSession $session, array $conversation): void
    {
        $id = $conversation['id'] ?? null;
        if (is_string($id)) {
            $this->publish($session, $this->sessionTopic($session) . $id, ['kind' => 'conversation', 'conversation' => $conversation]);
        }
    }

    public function removed(AuthSession $session, string $id): void
    {
        $this->publish($session, $this->sessionTopic($session) . $id, ['kind' => 'removed', 'id' => $id]);
    }

    /** @param array<string, mixed> $result */
    public function tool(AuthSession $session, Conversation $conversation, string $tool, string $action, string $operationId, array $result): void
    {
        $this->publish($session, $this->sessionTopic($session) . $conversation->id->toRfc4122(), [
            'kind'        => 'tool',
            'id'          => $conversation->id->toRfc4122(),
            'version'     => $conversation->version,
            'tool'        => $tool,
            'action'      => $action,
            'operationId' => $operationId,
            'result'      => $result,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function publish(AuthSession $session, string $topic, array $payload): void
    {
        try {
            $this->entityManager->refresh($session);
            if (!$session->isRefreshActive($this->clock->now())) {
                return;
            }
            $this->hub->publish(new Update($topic, json_encode($payload, JSON_THROW_ON_ERROR), private: true));
        } catch (\Throwable $error) {
            // A committed mutation remains successful when the hub is temporarily unavailable.
            $this->logger->warning('Chat event delivery failed: ' . $error::class);
        }
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

    public function subscribe(Request $request, Conversation $conversation): JsonResponse
    {
        $session = $request->attributes->get(AuthSession::class);
        if (!$session instanceof AuthSession || !$session->isAccessActive($this->clock->now())) {
            throw new ApiException(401, 'AUTH_REQUIRED');
        }
        $prefix = $this->sessionTopic($session);
        $expires = $this->clock->now()->modify('+5 minutes');
        $cookie = $this->authorization
        ->createCookie($request, ['urlpattern' => [$prefix . '*']], additionalClaims: [
            'exp' => $expires,
            'sub' => $session->getId()->toRfc4122(),
        ])
            ->withDomain(null);
        $response = new JsonResponse([
            'url'       => '/.well-known/mercure?match=' . rawurlencode($prefix . $conversation->id->toRfc4122()),
            'expiresIn' => 300,
        ], headers: ['Cache-Control' => 'no-store']);
        $response->headers->setCookie($cookie);

        return $response;
    }
}
