<?php

namespace App\Tests\Application\Auth;

use App\Api\ApiException;
use App\Application\Auth\AuthenticationPolicy;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class AuthenticationPolicyTest extends TestCase
{
    public function testVerifiedUserCanReceiveTokens(): void
    {
        (new AuthenticationPolicy())->assertCanIssueTokens($this->user());

        $this->addToAssertionCount(1);
    }

    public function testUnverifiedUserCannotReceiveTokens(): void
    {
        $user = $this->user();
        (new \ReflectionProperty(User::class, 'emailVerifiedAt'))
            ->setValue($user, null);

        try {
            (new AuthenticationPolicy())->assertCanIssueTokens($user);
            self::fail('An unverified user received tokens.');
        } catch (ApiException $exception) {
            self::assertSame(403, $exception->getStatus());
            self::assertSame(
                'EMAIL_NOT_VERIFIED',
                $exception->getErrorCode(),
            );
        }
    }

    private function user(): User
    {
        return new User(
            'family_admin',
            'family@example.test',
            'password-hash',
            'en',
            new \DateTimeImmutable('2026-07-31T00:00:00+00:00'),
        );
    }
}
