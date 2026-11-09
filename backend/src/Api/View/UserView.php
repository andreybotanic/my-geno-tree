<?php

namespace App\Api\View;

use App\Entity\MediaAttachment;
use App\Entity\User;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Uid\Uuid;

#[Map(source: User::class)]
final readonly class UserView
{
    /** @param list<array<string, mixed>> $bloodNetworkPresets */
    public function __construct(
        public Uuid $id,
        public string $username,
        public string $email,
        public string $preferredLanguage,
        public array $bloodNetworkPresets,
        #[Map(source: 'avatarAttachment', transform: [self::class, 'avatarUrl'])] public ?string $avatarUrl,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    public static function avatarUrl(?MediaAttachment $attachment): ?string
    {
        return null === $attachment ? null : '/me/avatar?media=' . $attachment->getMedia()->getId();
    }
}
