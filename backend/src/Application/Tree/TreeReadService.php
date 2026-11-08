<?php

namespace App\Application\Tree;

use App\Api\ApiException;
use App\Entity\Value\EventSource;
use App\Entity\Value\EventType;
use App\Repository\TreeReadRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

final readonly class TreeReadService
{
    public const array GROUPS = ['identity', 'life', 'places', 'metadata'];
    private const int MAX_BYTES = 128 * 1024;

    public function __construct(
        private TreeContextService $context,
        private TreeReadRepository $repository,
        private TreeReadViewFactory $views,
        private TreeVersion $versions,
        private EntityManagerInterface $entityManager,
        #[Autowire('%kernel.secret%')]
        private string $secret,
    ) {
    }

    /** @param list<string>|null $personIds
     * @param list<string>      $groups
     * @param list<string>|null $types
     *
     * @return array<string, mixed>
     */
    public function read(
        string $treeId,
        string $kind,
        ?array $personIds = null,
        array $groups = ['identity', 'life'],
        ?array $types = null,
        ?string $source = null,
        ?string $cursor = null,
        int $limit = 100,
        ?string $expectedEtag = null,
    ): array {
        if (!in_array($kind, ['people', 'parents', 'marriages', 'events'], true)
            || $limit < 1 || $limit > 200 || [] === $groups
            || !array_is_list($groups)
            || array_any($groups, static fn (mixed $group): bool => !in_array($group, self::GROUPS, true))
            || (null !== $source && null === EventSource::tryFrom($source))) {
            throw new ApiException(422, 'TREE_READ_INVALID');
        }
        if (null !== $types) {
            if (!array_is_list($types)) {
                throw new ApiException(422, 'TREE_READ_INVALID');
            }
            foreach ($types as $type) {
                if (!is_string($type) || null === EventType::tryFrom($type)) {
                    throw new ApiException(422, 'TREE_READ_INVALID');
                }
            }
            sort($types);
        }
        if (null !== $personIds) {
            if (!array_is_list($personIds)) {
                throw new ApiException(422, 'TREE_READ_INVALID');
            }
            foreach ($personIds as $id) {
                if (!is_string($id) || !Uuid::isValid($id)) {
                    throw new ApiException(422, 'TREE_READ_INVALID');
                }
            }
            sort($personIds);
        }
        sort($groups);
        /** @var list<'identity'|'life'|'places'|'metadata'> $groups */
        $tree = $this->context->tree($treeId);
        $this->entityManager->refresh($tree);
        $etag = $this->versions->etag($tree);
        if (null !== $expectedEtag) {
            $this->versions->assertMatches($tree, $expectedEtag);
        }
        $selection = hash(
            'sha256',
            json_encode([$treeId, $kind, $personIds, $groups, $types, $source], JSON_THROW_ON_ERROR),
        );
        $after = null === $cursor ? null : $this->decode($cursor, $selection, $etag);
        $query = $this->repository->query(
            $tree,
            $kind,
            $personIds,
            $types,
            $source,
            in_array('places', $groups, true) || 'people' !== $kind,
        );
        $total = $this->repository->total($query);
        $rows = $this->repository->page($query, $after, $limit + 1);
        $result = [
            'treeId'     => $treeId,
            'etag'       => $etag,
            'total'      => $total,
            'returned'   => 0,
            'nextCursor' => null,
            'items'      => [],
        ];
        if ('people' === $kind) {
            $result['groups'] = $groups;
        }
        $last = null;
        foreach ($rows as $row) {
            $item = match ($kind) {
                'people'               => $this->views->person($row, $groups),
                'parents', 'marriages' => $this->views->relationship($row, $kind),
                'events'               => $this->views->event($row),
            };
            $candidate = $result;
            $candidate['items'][] = $item;
            if ($result['returned'] >= $limit
                || strlen(json_encode($candidate, JSON_THROW_ON_ERROR)) > self::MAX_BYTES - 2048) {
                if (null === $last) {
                    throw new ApiException(413, 'TREE_READ_RECORD_TOO_LARGE');
                }
                $result['nextCursor'] = $this->encode($last, $selection, $etag);
                break;
            }
            $result['items'][] = $item;
            ++$result['returned'];
            assert(is_string($item['id']));
            $last = $item['id'];
        }
        $this->entityManager->refresh($tree);
        $this->versions->assertMatches($tree, $etag);

        return $result;
    }

    private function encode(string $after, string $selection, string $etag): string
    {
        $data = json_encode(['after' => $after, 'selection' => $selection, 'etag' => $etag], JSON_THROW_ON_ERROR);

        return base64_encode($data) . '.' . hash_hmac('sha256', $data, $this->secret);
    }

    private function decode(string $cursor, string $selection, string $etag): string
    {
        $parts = explode('.', $cursor);
        $data = base64_decode($parts[0], true);
        if (2 !== count($parts) || false === $data
            || !hash_equals(hash_hmac('sha256', $data, $this->secret), $parts[1])) {
            throw new ApiException(422, 'TREE_CURSOR_INVALID');
        }
        $value = json_decode($data, true);
        if (!is_array($value) || ($value['selection'] ?? null) !== $selection
            || !is_string($value['after'] ?? null) || !Uuid::isValid($value['after'])) {
            throw new ApiException(422, 'TREE_CURSOR_INVALID');
        }
        if (($value['etag'] ?? null) !== $etag) {
            throw new ApiException(412, 'TREE_VERSION_CONFLICT');
        }

        return $value['after'];
    }
}
