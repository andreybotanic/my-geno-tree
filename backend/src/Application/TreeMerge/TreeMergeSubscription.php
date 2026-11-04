<?php

namespace App\Application\TreeMerge;

use App\Api\ApiException;
use App\Entity\AuthSession;
use App\Entity\TreeMergeDraft;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\Authorization;

final readonly class TreeMergeSubscription
{
    public function __construct(private Authorization $authorization, private TreeMergeEvents $events, private ClockInterface $clock)
    {
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
