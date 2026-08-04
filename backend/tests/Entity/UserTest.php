<?php

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testItAddsNewLengthSettingsToExistingPresets(): void
    {
        $createdAt = new \DateTimeImmutable('2026-08-03T00:00:00+00:00');
        $user = new User('user', 'user@example.test', 'password-hash', 'ru', $createdAt);
        $oldForces = [
            'averageMovementPerSecondThreshold' => 1,
            'baseRepulsion'                     => 3,
            'descendantSideForce'               => 12,
            'personFlowHingeStrength'           => 8,
            'unrelatedParentNodeRepulsion'      => 6,
            'kinshipRepulsionStep'              => 0.3,
            'parentHingeSpringStrength'         => 4,
            'parentHingeTargetAngle'            => 45,
            'parentPairSpringStrength'          => 0.2,
            'sharedParentSpringStrength'        => 0.12,
        ];
        $user->changeBloodNetworkPresets([
            ['id' => 'weak', 'name' => 'Weak', 'isDefault' => false, 'forces' => $oldForces],
            ['id' => 'normal', 'name' => 'Normal', 'isDefault' => true, 'forces' => $oldForces],
        ], $createdAt);

        $normalPreset = $user->getBloodNetworkPresets()[1];

        self::assertArrayHasKey('forces', $normalPreset);
        self::assertIsArray($normalPreset['forces']);
        self::assertSame(300, $normalPreset['forces']['parentChildPathLength']);
        self::assertSame(1, $normalPreset['forces']['baseRepulsionDistanceExponent']);
        self::assertSame(24, $normalPreset['forces']['edgeNodeCapsuleThickness']);
        self::assertSame(12, $normalPreset['forces']['edgeNodeRepulsionStrength']);
        self::assertSame(0.58, $normalPreset['forces']['parentToFamilyLengthRatio']);
    }
}
