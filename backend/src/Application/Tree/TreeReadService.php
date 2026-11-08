<?php

namespace App\Application\Tree;

use App\Api\ApiException;
use App\Entity\FamilyTree;
use App\Entity\Value\EventSource;
use App\Entity\Value\EventType;
use App\Repository\GenealogicalEventRepository;
use App\Repository\MarriagePeriodRepository;
use App\Repository\ParentLinkRepository;
use App\Repository\PersonRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/** Coordinates version-consistent, size-bounded pages for a tree already authorized by the calling adapter. */
final readonly class TreeReadService
{
    private const int MAX_BYTES = 128 * 1024;

    public function __construct(
        private PersonRepository $people,
        private ParentLinkRepository $parents,
        private MarriagePeriodRepository $marriages,
        private GenealogicalEventRepository $events,
        private TreeVersion $versions,
        private EntityManagerInterface $entityManager,
        #[Autowire('%kernel.secret%')]
        private string $secret,
    ) {
    }

    /** @param list<string>|null $personIds
     * @param list<string> $groups
     *
     * @return array<string, mixed>
     */
    public function people(
        FamilyTree $tree,
        ?array $personIds = null,
        array $groups = ['identity', 'life'],
        ?string $cursor = null,
        int $limit = 100,
        ?string $expectedEtag = null,
    ): array {
        $invalidGroups = array_any(
            $groups,
            static fn (mixed $group): bool => !in_array($group, PersonRepository::READ_GROUPS, true),
        );
        if ([] === $groups || !array_is_list($groups) || $invalidGroups) {
            throw new ApiException(422, 'TREE_READ_INVALID');
        }
        sort($groups);
        /** @var list<'identity'|'life'|'places'|'metadata'> $groups */
        $personIds = $this->personIds($personIds);

        return $this->page(
            $tree,
            ['people', $personIds, $groups, null, null],
            $cursor,
            $limit,
            $expectedEtag,
            fn (?string $after, int $count): array => $this->people->readPage(
                $tree,
                $personIds,
                $groups,
                $after,
                $count,
            ),
            $groups,
        );
    }

    /** @param list<string>|null $personIds
     * @return array<string, mixed>
     */
    public function parents(
        FamilyTree $tree,
        ?array $personIds = null,
        ?string $cursor = null,
        int $limit = 100,
        ?string $expectedEtag = null,
    ): array {
        $personIds = $this->personIds($personIds);

        return $this->page(
            $tree,
            ['parents', $personIds, ['identity', 'life'], null, null],
            $cursor,
            $limit,
            $expectedEtag,
            fn (?string $after, int $count): array => $this->parents->readPage($tree, $personIds, $after, $count),
        );
    }

    /** @param list<string>|null $personIds
     * @return array<string, mixed>
     */
    public function marriages(
        FamilyTree $tree,
        ?array $personIds = null,
        ?string $cursor = null,
        int $limit = 100,
        ?string $expectedEtag = null,
    ): array {
        $personIds = $this->personIds($personIds);

        return $this->page(
            $tree,
            ['marriages', $personIds, ['identity', 'life'], null, null],
            $cursor,
            $limit,
            $expectedEtag,
            fn (?string $after, int $count): array => $this->marriages->readPage($tree, $personIds, $after, $count),
        );
    }

    /** @param list<string>|null $personIds
     * @param list<string>|null $types
     *
     * @return array<string, mixed>
     */
    public function events(
        FamilyTree $tree,
        ?array $personIds = null,
        ?array $types = null,
        ?string $source = null,
        ?string $cursor = null,
        int $limit = 100,
        ?string $expectedEtag = null,
    ): array {
        if (null !== $source && null === EventSource::tryFrom($source)) {
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
        $personIds = $this->personIds($personIds);

        return $this->page(
            $tree,
            ['events', $personIds, ['identity', 'life'], $types, $source],
            $cursor,
            $limit,
            $expectedEtag,
            fn (?string $after, int $count): array => $this->events->readPage(
                $tree,
                $personIds,
                $types,
                $source,
                $after,
                $count,
            ),
        );
    }

    /** @param list<string>|null $personIds
     * @return list<string>|null
     */
    private function personIds(?array $personIds): ?array
    {
        if (null === $personIds) {
            return null;
        }
        if (!array_is_list($personIds)
            || array_any($personIds, static fn (mixed $id): bool => !is_string($id) || !Uuid::isValid($id))) {
            throw new ApiException(422, 'TREE_READ_INVALID');
        }
        sort($personIds);

        return $personIds;
    }

    /** @param list<mixed> $selection
     * @param \Closure(?string, int): array{total: int, items: list<array<string, mixed>>} $read
     * @param list<string>|null                                                            $groups
     *
     * @return array<string, mixed>
     */
    private function page(
        FamilyTree $tree,
        array $selection,
        ?string $cursor,
        int $limit,
        ?string $expectedEtag,
        \Closure $read,
        ?array $groups = null,
    ): array {
        if ($limit < 1 || $limit > 200) {
            throw new ApiException(422, 'TREE_READ_INVALID');
        }
        $this->entityManager->refresh($tree);
        $etag = $this->versions->etag($tree);
        if (null !== $expectedEtag) {
            $this->versions->assertMatches($tree, $expectedEtag);
        }
        $treeId = $tree->getId()->toRfc4122();
        $selectionHash = hash('sha256', json_encode([$treeId, ...$selection], JSON_THROW_ON_ERROR));
        $after = null === $cursor ? null : $this->decode($cursor, $selectionHash, $etag);
        $page = $read($after, $limit + 1);
        $result = [
            'treeId'     => $treeId,
            'etag'       => $etag,
            'total'      => $page['total'],
            'returned'   => 0,
            'nextCursor' => null,
            'items'      => [],
        ];
        if (null !== $groups) {
            $result['groups'] = $groups;
        }
        $last = null;
        foreach ($page['items'] as $item) {
            $candidate = $result;
            $candidate['items'][] = $item;
            if ($result['returned'] >= $limit
                || strlen(json_encode($candidate, JSON_THROW_ON_ERROR)) > self::MAX_BYTES - 2048) {
                if (null === $last) {
                    throw new ApiException(413, 'TREE_READ_RECORD_TOO_LARGE');
                }
                $result['nextCursor'] = $this->encode($last, $selectionHash, $etag);
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
