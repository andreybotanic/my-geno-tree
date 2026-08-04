<?php

namespace App\Request\Profile;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[Assert\Callback(callback: 'validate')]
final readonly class UpdateBloodNetworkPresetsRequest
{
    /**
     * @param list<array{
     *     id: 'weak'|'normal',
     *     name: string,
     *     isDefault: bool,
     *     forces: array<string, float|int>
     * }> $presets
     */
    public function __construct(
        #[Assert\Count(exactly: 2, exactMessage: 'blood_network_presets_count_invalid')]
        #[Assert\All([
            new Assert\Collection(
                fields: [
                    'id'        => [new Assert\Choice(choices: ['weak', 'normal'])],
                    'name'      => [new Assert\NotBlank(), new Assert\Length(max: 80)],
                    'isDefault' => [new Assert\Type('bool')],
                    'forces'    => [new Assert\Collection(fields: [
                        'averageMovementPerSecondThreshold' => [new Assert\Range(min: 0.1, max: 5)],
                        'baseRepulsion'                     => [new Assert\Range(min: 0.5, max: 8)],
                        'baseRepulsionDistanceExponent'     => [new Assert\Range(min: 1, max: 3)],
                        'descendantSideForce'               => [new Assert\Range(min: 0, max: 24)],
                        'personFlowHingeStrength'           => [new Assert\Range(min: 0, max: 50)],
                        'parentChildPathLength'             => [new Assert\Range(min: 100, max: 500)],
                        'unrelatedParentNodeRepulsion'      => [new Assert\Range(min: 0, max: 12)],
                        'kinshipRepulsionStep'              => [new Assert\Range(min: 0, max: 0.6)],
                        'parentHingeSpringStrength'         => [new Assert\Range(min: 0, max: 10)],
                        'parentHingeTargetAngle'            => [new Assert\Range(min: 10, max: 120)],
                        'parentPairSpringStrength'          => [new Assert\Range(min: 0.04, max: 0.5)],
                        'parentToFamilyLengthRatio'         => [new Assert\Range(min: 0, max: 1)],
                        'sharedParentSpringStrength'        => [new Assert\Range(min: 0.02, max: 2)],
                    ])],
                ],
            ),
        ])]
        public array $presets,
    ) {
    }

    public function validate(ExecutionContextInterface $context): void
    {
        $ids = array_filter(array_column($this->presets, 'id'), 'is_string');
        sort($ids);
        $defaultCount = count(array_filter(
            $this->presets,
            static fn (mixed $preset): bool => is_array($preset)
                && true === ($preset['isDefault'] ?? false),
        ));
        if (['normal', 'weak'] !== array_values(array_unique($ids))) {
            $context->buildViolation('blood_network_preset_ids_invalid')
                ->atPath('presets')
                ->addViolation();
        }
        if (1 !== $defaultCount) {
            $context->buildViolation('blood_network_preset_default_invalid')
                ->atPath('presets')
                ->addViolation();
        }
    }
}
