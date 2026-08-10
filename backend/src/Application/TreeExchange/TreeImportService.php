<?php

namespace App\Application\TreeExchange;

use App\Api\ApiException;
use App\Application\Tree\TreeNamePolicy;
use App\Entity\EventPlace;
use App\Entity\FamilyTree;
use App\Entity\GenealogicalEvent;
use App\Entity\MarriagePeriod;
use App\Entity\ParentLink;
use App\Entity\Person;
use App\Entity\User;
use App\Entity\Value\EventType;
use App\Entity\Value\KnownText;
use App\Entity\Value\ParentRole;
use App\Repository\FamilyTreeRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class TreeImportService
{
    private const int MAX_FILE_SIZE = 10 * 1024 * 1024;

    public function __construct(
        private Gedcom55Parser $parser,
        private GedcomEventPlaceFactory $places,
        private GedcomPlaceRecognizer $placeRecognizer,
        private TreeNamePolicy $names,
        private FamilyTreeRepository $trees,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private TranslatorInterface $translator,
    ) {
    }

    /** @return array<string, mixed> */
    public function inspect(UploadedFile $file): array
    {
        return $this->document($file)->inspection();
    }

    public function import(
        User $owner,
        string $requestedName,
        UploadedFile $file,
        bool $recognizePlaces = false,
    ): ImportedTree {
        $name = $this->names->normalize($requestedName);
        $document = $this->document($file);
        if ($this->trees->nameExistsForOwner($owner, $name)) {
            throw $this->nameConflict();
        }
        $recognizedPlaces = $recognizePlaces
            ? $this->placeRecognizer->recognize($document, $owner->getPreferredLanguage())
            : [];

        try {
            return $this->entityManager->wrapInTransaction(function () use ($owner, $name, $document, $recognizedPlaces): ImportedTree {
                $createdAt = $this->clock->now();
                $tree = new FamilyTree($owner, $name, $createdAt);
                $this->entityManager->persist($tree);

                /** @var array<string, Person> $people */
                $people = [];
                foreach ($document->people as $data) {
                    $person = new Person(
                        $tree,
                        $this->knownText($data['birthSurname']),
                        $this->knownText($data['givenName']),
                        KnownText::unknown(),
                        $data['gender'],
                        $data['birthDate'],
                        $data['deathDate'],
                        $createdAt,
                    );
                    $people[$data['id']] = $person;
                    $this->entityManager->persist($person);
                    $birthPlace = $this->importedPlace($data['birthPlace'], $recognizedPlaces);
                    if (null !== $birthPlace) {
                        $person->changeBirthPlace($birthPlace, $createdAt);
                    }
                    $deathPlace = $this->importedPlace($data['deathPlace'], $recognizedPlaces);
                    if (null !== $deathPlace) {
                        $person->changeDeathPlace($deathPlace, $createdAt);
                    }
                    $this->entityManager->persist(GenealogicalEvent::automaticPersonLifecycle(
                        $person,
                        EventType::BIRTH,
                        $data['birthDate'],
                        $createdAt,
                        $birthPlace,
                    ));
                    if (null !== $data['deathDate']) {
                        $this->entityManager->persist(GenealogicalEvent::automaticPersonLifecycle(
                            $person,
                            EventType::DEATH,
                            $data['deathDate'],
                            $createdAt,
                            $deathPlace,
                        ));
                    }
                    foreach ($data['events'] as $event) {
                        $this->entityManager->persist(GenealogicalEvent::manual(
                            $person,
                            'RESI' === $event['gedcomTag']
                                ? EventType::RESIDENCE
                                : EventType::CUSTOM,
                            $event['date'],
                            'RESI' === $event['gedcomTag']
                                ? null
                                : $this->eventTitle(
                                    $event['gedcomTag'],
                                    $event['title'],
                                    $owner->getPreferredLanguage(),
                                ),
                            $event['description'],
                            ['gedcomTag' => $event['gedcomTag']],
                            $createdAt,
                            $this->importedPlace($event['place'], $recognizedPlaces),
                        ));
                    }
                }

                foreach ($document->families as $family) {
                    foreach (['husband' => ParentRole::FATHER, 'wife' => ParentRole::MOTHER] as $key => $role) {
                        $parentId = $family[$key];
                        if (null === $parentId) {
                            continue;
                        }
                        foreach ($family['children'] as $childId) {
                            $link = new ParentLink($tree, $people[$parentId], $people[$childId], $role);
                            $this->entityManager->persist($link);
                            $this->entityManager->persist(GenealogicalEvent::automaticRelated(
                                $people[$parentId],
                                EventType::CHILD_BIRTH,
                                $people[$childId]->getBirthDate(),
                                'parent_link',
                                $link->getId(),
                                ['childId' => $people[$childId]->getId()->toRfc4122()],
                                $createdAt,
                                $people[$childId]->getBirthPlace(),
                            ));
                        }
                    }
                    if ($family['unmarried'] || null === $family['husband'] || null === $family['wife']) {
                        continue;
                    }
                    $male = $people[$family['husband']];
                    $female = $people[$family['wife']];
                    $marriage = new MarriagePeriod(
                        $tree,
                        $male,
                        $female,
                        $family['marriageDate'],
                        $family['dissolutionDate'],
                        $createdAt,
                    );
                    $this->entityManager->persist($marriage);
                    $marriagePlace = $this->importedPlace($family['marriagePlace'], $recognizedPlaces);
                    if (null !== $marriagePlace) {
                        $marriage->changeMarriagePlace($marriagePlace, $createdAt);
                    }
                    $dissolutionPlace = $this->importedPlace($family['dissolutionPlace'], $recognizedPlaces);
                    if (null !== $dissolutionPlace) {
                        $marriage->changeDissolutionPlace($dissolutionPlace, $createdAt);
                    }
                    foreach ([[$male, $female], [$female, $male]] as [$person, $partner]) {
                        $data = ['partnerId' => $partner->getId()->toRfc4122()];
                        $this->entityManager->persist(GenealogicalEvent::automaticRelated(
                            $person,
                            EventType::MARRIAGE,
                            $family['marriageDate'],
                            'marriage_period',
                            $marriage->getId(),
                            $data,
                            $createdAt,
                            $marriagePlace,
                        ));
                        if (null !== $family['dissolutionDate']) {
                            $this->entityManager->persist(GenealogicalEvent::automaticRelated(
                                $person,
                                EventType::DIVORCE,
                                $family['dissolutionDate'],
                                'marriage_period',
                                $marriage->getId(),
                                $data,
                                $createdAt,
                                $dissolutionPlace,
                            ));
                        }
                    }
                }

                $tree->referencePerson(array_values($people)[0]);

                return new ImportedTree($tree, count($people));
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw $this->nameConflict($exception);
        }
    }

    private function document(UploadedFile $file): GedcomImportDocument
    {
        if (in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            $this->fail('TREE_IMPORT_FILE_TOO_LARGE');
        }
        if (!$file->isValid()) {
            $this->fail('TREE_IMPORT_FILE_INVALID');
        }
        $size = $file->getSize();
        if (false === $size || $size > self::MAX_FILE_SIZE) {
            $this->fail('TREE_IMPORT_FILE_TOO_LARGE');
        }
        $contents = file_get_contents($file->getPathname());
        if (false === $contents || '' === $contents) {
            $this->fail('TREE_IMPORT_FILE_INVALID');
        }

        return $this->parser->parse($contents);
    }

    private function knownText(?string $value): KnownText
    {
        return null === $value ? KnownText::unknown() : KnownText::known($value);
    }

    /**
     * @param array{name: string, latitude: float|null, longitude: float|null}|null $data
     * @param array<string, array{name: string, latitude: float, longitude: float}> $recognizedPlaces
     */
    private function importedPlace(?array $data, array $recognizedPlaces): ?EventPlace
    {
        if (null === $data) {
            return null;
        }
        if (null === $data['latitude'] && isset($recognizedPlaces[$data['name']])) {
            $data = $recognizedPlaces[$data['name']];
        }
        $place = $this->places->create($data);
        if (null === $place) {
            return null;
        }
        $this->entityManager->persist($place);

        return $place;
    }

    private function eventTitle(
        string $gedcomTag,
        ?string $customTitle,
        string $language,
    ): string {
        if ('EVEN' === $gedcomTag && null !== $customTitle) {
            return $customTitle;
        }

        return $this->translator->trans(
            'tree_import.event_title.' . strtolower($gedcomTag),
            locale: $language,
        );
    }

    private function fail(string $code): never
    {
        throw new ApiException(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, $code, strtolower($code));
    }

    private function nameConflict(?UniqueConstraintViolationException $previous = null): ApiException
    {
        return new ApiException(
            JsonResponse::HTTP_CONFLICT,
            'TREE_NAME_CONFLICT',
            'tree_name_conflict',
            ['name' => ['tree_name_not_unique']],
            previous: $previous,
        );
    }
}
