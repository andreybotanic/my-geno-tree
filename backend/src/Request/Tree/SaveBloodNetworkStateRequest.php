<?php

namespace App\Request\Tree;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class SaveBloodNetworkStateRequest
{
    /**
     * @param list<array{id: string, coordinates: array{x: float|int, y: float|int}}> $nodes
     * @param array{x: float|int, y: float|int, zoom: float|int}                      $viewport
     * @param array<string, float|int>                                                $forces
     */
    public function __construct(
        #[Assert\Count(min: 1, max: 10000)]
        #[Assert\All([
            new Assert\Collection(fields: [
                'id'          => [new Assert\NotBlank(), new Assert\Length(max: 200)],
                'coordinates' => [new Assert\Collection(fields: [
                    'x' => [new Assert\Type('numeric'), new Assert\Range(min: -1000000, max: 1000000)],
                    'y' => [new Assert\Type('numeric'), new Assert\Range(min: -1000000, max: 1000000)],
                ])],
            ]),
        ])]
        public array $nodes,
        #[Assert\Collection(fields: [
            'x'    => [new Assert\Type('numeric'), new Assert\Range(min: -1000000, max: 1000000)],
            'y'    => [new Assert\Type('numeric'), new Assert\Range(min: -1000000, max: 1000000)],
            'zoom' => [new Assert\Type('numeric'), new Assert\Range(min: 0.1, max: 2)],
        ])]
        public array $viewport,
        #[Assert\Collection(fields: [
            'averageMovementPerSecondThreshold' => [new Assert\Range(min: 0.1, max: 5)],
            'baseRepulsion'                     => [new Assert\Range(min: 0.5, max: 8)],
            'baseRepulsionDistanceExponent'     => [new Assert\Range(min: 1, max: 3)],
            'descendantSideForce'               => [new Assert\Range(min: 0, max: 24)],
            'edgeNodeCapsuleThickness'          => [new Assert\Range(min: 0, max: 100)],
            'edgeNodeRepulsionStrength'         => [new Assert\Range(min: 0, max: 30)],
            'personFlowHingeStrength'           => [new Assert\Range(min: 0, max: 50)],
            'parentChildPathLength'             => [new Assert\Range(min: 100, max: 500)],
            'unrelatedParentNodeRepulsion'      => [new Assert\Range(min: 0, max: 12)],
            'kinshipRepulsionStep'              => [new Assert\Range(min: 0, max: 1)],
            'kinshipRepulsionDistanceExponent'  => [new Assert\Range(min: 1, max: 3)],
            'parentHingeSpringStrength'         => [new Assert\Range(min: 0, max: 10)],
            'parentHingeTargetAngle'            => [new Assert\Range(min: 10, max: 120)],
            'parentPairSpringStrength'          => [new Assert\Range(min: 0.04, max: 0.5)],
            'parentToFamilyLengthRatio'         => [new Assert\Range(min: 0, max: 1)],
            'sharedParentSpringStrength'        => [new Assert\Range(min: 0.02, max: 2)],
        ])]
        public array $forces,
    ) {
    }

    /** @return array<string, mixed> */
    public function state(): array
    {
        return [
            'forces'   => $this->forces,
            'nodes'    => $this->nodes,
            'viewport' => $this->viewport,
        ];
    }
}
