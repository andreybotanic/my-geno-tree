<?php

namespace App\Controller\Api\Tree;

use App\Api\ApiException;
use App\Api\View\DocumentView;
use App\Application\Document\DocumentService;
use App\Application\Tree\TreeVersion;
use App\Entity\FamilyTree;
use App\Security\FamilyTreeVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;

#[Route('/api/v1/trees/{treeId}/documents')]
final readonly class DocumentController
{
    public function __construct(
        private SerializerInterface $serializer,
        private DocumentService $documents,
        private ObjectMapperInterface $mapper,
        private TreeVersion $versions,
    ) {
    }

    #[Route('', name: 'api_documents_list', methods: ['GET'])]
    #[IsGranted(FamilyTreeVoter::VIEW, subject: 'tree', statusCode: 404)]
    public function listing(Request $request, #[MapEntity(id: 'treeId')] FamilyTree $tree): JsonResponse
    {
        $scope = $request->query->getString('scope', 'all');
        $limit = $request->query->getString('limit', '50');
        if (!ctype_digit($limit)) {
            throw new ApiException(422, 'DOCUMENT_QUERY_INVALID');
        }

        return JsonResponse::fromJsonString(
            $this->serializer->serialize(
                $this->documents->listing(
                    $tree,
                    $request->query->get('personId'),
                    $scope,
                    $request->query->get('cursor'),
                    (int)$limit,
                ),
                'json',
            ),
        );
    }

    #[Route('', name: 'api_documents_upload', methods: ['POST'])]
    #[IsGranted(FamilyTreeVoter::EDIT, subject: 'tree', statusCode: 404)]
    public function upload(Request $request, #[MapEntity(id: 'treeId')] FamilyTree $tree): JsonResponse
    {
        $file = $request->files->get('file');
        $personId = $request->request->get('personId');
        if (null !== $personId && !is_string($personId)) {
            throw new ApiException(422, 'DOCUMENT_UPLOAD_INVALID');
        }
        $document = $this->documents->upload(
            $tree,
            is_string($personId) && '' !== $personId ? $personId : null,
            $file instanceof UploadedFile ? $file : null,
            $request->headers->get('If-Match'),
        );
        $etag = $this->versions->etag($tree);

        return JsonResponse::fromJsonString(
            $this->serializer->serialize(
                ['document' => $this->mapper->map($document, DocumentView::class), 'etag' => $etag],
                'json',
            ),
            202,
            ['ETag' => $etag],
        );
    }

    #[Route('/{documentId}/download', name: 'api_documents_download', methods: ['GET'])]
    #[IsGranted(FamilyTreeVoter::VIEW, subject: 'tree', statusCode: 404)]
    public function download(#[MapEntity(id: 'treeId')] FamilyTree $tree, string $documentId): Response
    {
        $document = $this->documents->require($tree, $documentId);
        $response = new Response(
            $this->documents->contents($document),
            headers: [
                'Content-Type'           => $document->getMimeType(),
                'Content-Length'         => (string)$document->getOriginalSizeBytes(),
                'Cache-Control'          => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(
                'attachment',
                $document->getOriginalName(),
                'document.' . ('text/markdown' === $document->getMimeType() ? 'md' : 'txt'),
            )
        );

        return $response;
    }

    #[Route('/{documentId}', name: 'api_documents_delete', methods: ['DELETE'])]
    #[IsGranted(FamilyTreeVoter::EDIT, subject: 'tree', statusCode: 404)]
    public function delete(
        Request $request,
        #[MapEntity(id: 'treeId')] FamilyTree $tree,
        string $documentId,
    ): JsonResponse {
        $this->documents->delete($tree, $documentId, $request->headers->get('If-Match'));
        $etag = $this->versions->etag($tree);

        return new JsonResponse(['etag' => $etag], headers: ['ETag' => $etag]);
    }
}
