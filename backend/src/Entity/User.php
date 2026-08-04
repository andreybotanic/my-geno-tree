<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 32, unique: true)]
    private string $username;

    #[ORM\Column(length: 254, unique: true)]
    private string $email;

    #[ORM\Column(length: 255)]
    private string $passwordHash;

    #[ORM\Column(length: 2)]
    private string $preferredLanguage;

    /** @var list<array<string, mixed>> */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $bloodNetworkPresets;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\OneToOne(mappedBy: 'user', targetEntity: MediaAttachment::class)]
    private ?MediaAttachment $avatarAttachment = null;

    public function __construct(
        string $username,
        string $email,
        string $passwordHash,
        string $preferredLanguage,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
        $this->username = $username;
        $this->email = $email;
        $this->passwordHash = $passwordHash;
        $this->preferredLanguage = $preferredLanguage;
        $this->bloodNetworkPresets = self::defaultBloodNetworkPresets();
        $this->emailVerifiedAt = $createdAt;
        $this->createdAt = $createdAt;
        $this->updatedAt = $createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUserIdentifier(): string
    {
        return $this->id->toRfc4122();
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getPreferredLanguage(): string
    {
        return $this->preferredLanguage;
    }

    /** @return list<array<string, mixed>> */
    public function getBloodNetworkPresets(): array
    {
        $defaultPresets = self::defaultBloodNetworkPresets();
        if ([] === $this->bloodNetworkPresets) {
            return $defaultPresets;
        }

        return array_map(static function (array $preset): array {
            $presetId = $preset['id'] ?? null;
            $defaults = is_string($presetId)
                ? self::defaultBloodNetworkForces($presetId)
                : [];
            $storedForces = $preset['forces'] ?? [];
            $forces = is_array($storedForces) ? $storedForces : [];

            return [
                ...$preset,
                'forces' => [...$defaults, ...$forces],
            ];
        }, $this->bloodNetworkPresets);
    }

    public function getEmailVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getAvatarAttachment(): ?MediaAttachment
    {
        return $this->avatarAttachment;
    }

    public function attachAvatar(
        MediaAttachment $attachment,
        \DateTimeImmutable $changedAt,
    ): void {
        $this->avatarAttachment = $attachment;
        $this->updatedAt = $changedAt;
    }

    public function detachAvatar(\DateTimeImmutable $changedAt): void
    {
        $this->avatarAttachment = null;
        $this->updatedAt = $changedAt;
    }

    public function changePreferredLanguage(
        string $preferredLanguage,
        \DateTimeImmutable $changedAt,
    ): void {
        $this->preferredLanguage = $preferredLanguage;
        $this->updatedAt = $changedAt;
    }

    /** @param list<array<string, mixed>> $presets */
    public function changeBloodNetworkPresets(
        array $presets,
        \DateTimeImmutable $changedAt,
    ): void {
        $this->bloodNetworkPresets = $presets;
        $this->updatedAt = $changedAt;
    }

    /** @return list<array<string, mixed>> */
    private static function defaultBloodNetworkPresets(): array
    {
        return [
            [
                'id'        => 'weak',
                'name'      => 'Слабый',
                'isDefault' => false,
                'forces'    => [
                    'averageMovementPerSecondThreshold' => 1,
                    'baseRepulsion'                     => 8,
                    'baseRepulsionDistanceExponent'     => 1,
                    'descendantSideForce'               => 0,
                    'edgeNodeCapsuleThickness'          => 100,
                    'edgeNodeRepulsionStrength'         => 30,
                    'personFlowHingeStrength'           => 0,
                    'parentChildPathLength'             => 300,
                    'unrelatedParentNodeRepulsion'      => 12,
                    'kinshipRepulsionStep'              => 3,
                    'kinshipRepulsionDistanceExponent'  => 1,
                    'parentHingeSpringStrength'         => 0,
                    'parentHingeTargetAngle'            => 10,
                    'parentPairSpringStrength'          => 0.04,
                    'parentToFamilyLengthRatio'         => 0.58,
                    'sharedParentSpringStrength'        => 0.02,
                ],
            ],
            [
                'id'        => 'normal',
                'name'      => 'Нормальный',
                'isDefault' => true,
                'forces'    => [
                    'averageMovementPerSecondThreshold' => 1,
                    'baseRepulsion'                     => 3,
                    'baseRepulsionDistanceExponent'     => 1,
                    'descendantSideForce'               => 12,
                    'edgeNodeCapsuleThickness'          => 24,
                    'edgeNodeRepulsionStrength'         => 12,
                    'personFlowHingeStrength'           => 8,
                    'parentChildPathLength'             => 300,
                    'unrelatedParentNodeRepulsion'      => 6,
                    'kinshipRepulsionStep'              => 0.3,
                    'kinshipRepulsionDistanceExponent'  => 1,
                    'parentHingeSpringStrength'         => 4,
                    'parentHingeTargetAngle'            => 45,
                    'parentPairSpringStrength'          => 0.2,
                    'parentToFamilyLengthRatio'         => 0.58,
                    'sharedParentSpringStrength'        => 0.12,
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function defaultBloodNetworkForces(string $presetId): array
    {
        foreach (self::defaultBloodNetworkPresets() as $preset) {
            if ($presetId === $preset['id']) {
                $forces = $preset['forces'];

                return is_array($forces) ? $forces : [];
            }
        }

        return [];
    }
}
