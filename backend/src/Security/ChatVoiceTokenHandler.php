<?php

namespace App\Security;

use App\Application\Chat\Voice\ChatVoiceSessions;
use App\Entity\AuthSession;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

final readonly class ChatVoiceTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(private ChatVoiceSessions $sessions, private RequestStack $requests)
    {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        try {
            $session = $this->sessions->authenticate($accessToken);
            $this->requests->getMainRequest()?->attributes->set(AuthSession::class, $session);

            return new UserBadge($session->getUser()->getUserIdentifier(), $session->getUser(...));
        } catch (\Throwable) {
            throw new BadCredentialsException('Invalid voice recording credential.');
        }
    }
}
