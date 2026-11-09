<?php

namespace App\Controller\Api\Person;

use App\Api\MediaResponseFactory;
use App\Api\View\PersonView;
use App\Application\Media\CropAreaPolicy;
use App\Application\Media\MediaService;
use App\Application\Person\PersonLookup;
use App\Application\Tree\TreeVersion;
use App\Entity\FamilyTree;
use App\Entity\MediaVariantType;
use App\Security\FamilyTreeVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class PersonPhotoController extends AbstractController
{
    public function __construct(
        private readonly PersonLookup $people,
        private readonly CropAreaPolicy $input,
        private readonly MediaService $media,
        private readonly MediaResponseFactory $responses,
        private readonly ObjectMapperInterface $mapper,
        private readonly TreeVersion $versions,
    ) {
    }

    #[Route(
        '/api/v1/trees/{treeId}/people/{personId}/photo',
        name: 'api_person_photo_get',
        methods: ['GET'],
    )]
    #[IsGranted(
        FamilyTreeVoter::VIEW,
        subject: 'tree',
        statusCode: JsonResponse::HTTP_NOT_FOUND,
    )]
    public function get(
        #[MapEntity(id: 'treeId')] FamilyTree $tree,
        string $personId,
    ): Response {
        return $this->image($tree, $personId, MediaVariantType::FULL);
    }

    #[Route(
        '/api/v1/trees/{treeId}/people/{personId}/photo/{variant}',
        name: 'api_person_photo_variant_get',
        requirements: ['variant' => 'portrait|thumbnail'],
        methods: ['GET'],
    )]
    #[IsGranted(
        FamilyTreeVoter::VIEW,
        subject: 'tree',
        statusCode: JsonResponse::HTTP_NOT_FOUND,
    )]
    public function variant(
        #[MapEntity(id: 'treeId')] FamilyTree $tree,
        string $personId,
        string $variant,
    ): Response {
        return $this->image(
            $tree,
            $personId,
            'portrait' === $variant
                ? MediaVariantType::PORTRAIT
                : MediaVariantType::THUMBNAIL,
        );
    }

    #[Route(
        '/api/v1/trees/{treeId}/people/{personId}/photo',
        name: 'api_person_photo_upload',
        methods: ['POST'],
    )]
    #[IsGranted(
        FamilyTreeVoter::EDIT,
        subject: 'tree',
        statusCode: JsonResponse::HTTP_NOT_FOUND,
    )]
    public function upload(
        Request $request,
        #[MapEntity(id: 'treeId')] FamilyTree $tree,
        string $personId,
    ): JsonResponse {
        $person = $this->people->require($tree, $personId);
        $this->media->uploadPersonPhoto(
            $tree,
            $person,
            $tree->getOwner(),
            $this->input->uploadedFile($request),
            $this->input->fromRequest($request),
            $request->headers->get('If-Match'),
        );

        return $this->personResponse($tree, $person);
    }

    #[Route(
        '/api/v1/trees/{treeId}/people/{personId}/photo',
        name: 'api_person_photo_delete',
        methods: ['DELETE'],
    )]
    #[IsGranted(
        FamilyTreeVoter::EDIT,
        subject: 'tree',
        statusCode: JsonResponse::HTTP_NOT_FOUND,
    )]
    public function delete(
        Request $request,
        #[MapEntity(id: 'treeId')] FamilyTree $tree,
        string $personId,
    ): JsonResponse {
        $person = $this->people->require($tree, $personId);
        $this->media->deletePersonPhoto(
            $tree,
            $person,
            $request->headers->get('If-Match'),
        );

        return $this->personResponse($tree, $person);
    }

    private function image(
        FamilyTree $tree,
        string $personId,
        MediaVariantType $type,
    ): Response {
        $variant = $this->media->personPhoto(
            $this->people->require($tree, $personId),
            $type,
        );

        return $this->responses->create(
            $variant,
            $this->media->contents($variant),
        );
    }

    private function personResponse(
        FamilyTree $tree,
        \App\Entity\Person $person,
    ): JsonResponse {
        $etag = $this->versions->etag($tree);

        return $this->json(
            [
                'person' => $this->mapper->map($person, PersonView::class),
                'etag'   => $etag,
            ],
            headers: ['ETag' => $etag],
        );
    }
}
