<?php

namespace App\Tests\Api\Tree;

use App\Tests\Api\ApiWebTestCase;

final class BloodNetworkStateApiTest extends ApiWebTestCase
{
    public function testOwnerCanSaveAndReloadTheCompleteBloodNetworkState(): void
    {
        $accessToken = $this->register();
        $tree = $this->createTree($accessToken);
        $firstSnapshot = $this->createFirstPerson($accessToken, $tree);
        $personId = $firstSnapshot['people'][0]['id'];
        $state = [
            'forces' => [
                'averageMovementPerSecondThreshold' => 1.5,
                'baseRepulsion'                     => 4,
                'baseRepulsionDistanceExponent'     => 3,
                'descendantSideForce'               => 13,
                'edgeNodeCapsuleThickness'          => 24,
                'edgeNodeRepulsionStrength'         => 12,
                'personFlowHingeStrength'           => 50,
                'parentChildPathLength'             => 280,
                'unrelatedParentNodeRepulsion'      => 5,
                'kinshipRepulsionStep'              => 3,
                'kinshipRepulsionDistanceExponent'  => 3,
                'parentHingeSpringStrength'         => 3,
                'parentHingeTargetAngle'            => 55,
                'parentPairSpringStrength'          => 0.18,
                'parentToFamilyLengthRatio'         => 0.6,
                'sharedParentSpringStrength'        => 2,
            ],
            'nodes' => [[
                'id'          => $personId,
                'coordinates' => ['x' => -240.5, 'y' => 180.25],
            ]],
            'viewport' => ['x' => 120, 'y' => -80, 'zoom' => 0.55],
        ];

        $this->jsonRequest(
            'PUT',
            '/api/v1/trees/' . $tree['id'] . '/blood-network-state',
            $state,
            [
                'Authorization' => 'Bearer ' . $accessToken,
                'If-Match'      => $firstSnapshot['etag'],
            ],
        );
        self::assertResponseIsSuccessful();
        $savedSnapshot = $this->responseData();
        self::assertSame($state, $savedSnapshot['bloodNetworkState']);
        self::assertNotSame($firstSnapshot['etag'], $savedSnapshot['etag']);
        self::assertSame(
            $this->client->getResponse()->headers->get('ETag'),
            $savedSnapshot['etag'],
        );

        $this->jsonRequest(
            'GET',
            '/api/v1/trees/' . $tree['id'] . '/snapshot',
            headers: ['Authorization' => 'Bearer ' . $accessToken],
        );
        self::assertResponseIsSuccessful();
        self::assertSame($state, $this->responseData()['bloodNetworkState']);

        $this->jsonRequest(
            'PUT',
            '/api/v1/trees/' . $tree['id'] . '/blood-network-state',
            $state,
            [
                'Authorization' => 'Bearer ' . $accessToken,
                'If-Match'      => $firstSnapshot['etag'],
            ],
        );
        self::assertResponseStatusCodeSame(412);
        self::assertSame('TREE_VERSION_CONFLICT', $this->responseString('code'));
    }

    /** @return array{id: string, etag: string} */
    private function createTree(string $accessToken): array
    {
        $this->jsonRequest(
            'POST',
            '/api/v1/trees',
            ['name'          => 'Force map'],
            ['Authorization' => 'Bearer ' . $accessToken],
        );
        self::assertResponseStatusCodeSame(201);

        /** @var array{id: string, etag: string} $tree */
        $tree = $this->responseData();

        return $tree;
    }

    /**
     * @param array{id: string, etag: string} $tree
     *
     * @return array{etag: string, people: list<array{id: string}>}
     */
    private function createFirstPerson(string $accessToken, array $tree): array
    {
        $this->jsonRequest(
            'POST',
            '/api/v1/trees/' . $tree['id'] . '/graph-mutations',
            [
                'type'   => 'create_first_person',
                'person' => [
                    'birthSurname' => ['state' => 'unknown'],
                    'givenName'    => ['state' => 'unknown'],
                    'patronymic'   => ['state' => 'unknown'],
                    'gender'       => 'unknown',
                    'birthDate'    => ['state' => 'unknown'],
                    'deathDate'    => null,
                ],
            ],
            [
                'Authorization' => 'Bearer ' . $accessToken,
                'If-Match'      => $tree['etag'],
            ],
        );
        self::assertResponseStatusCodeSame(201);

        /** @var array{etag: string, people: list<array{id: string}>} $snapshot */
        $snapshot = $this->responseData();

        return $snapshot;
    }

    private function register(): string
    {
        $this->jsonRequest('POST', '/api/v1/auth/register', $this->validRegistration());
        self::assertResponseStatusCodeSame(201);

        return $this->responseString('accessToken');
    }
}
