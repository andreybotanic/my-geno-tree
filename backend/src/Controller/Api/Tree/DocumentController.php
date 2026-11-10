<?php

namespace App\Controller\Api\Tree;

use App\Api\ApiException;
use App\Api\RequestBodyMapper;
use App\Api\View\DocumentView;
use App\Application\Document\DocumentService;
use App\Application\Tree\TreeVersion;
use App\Entity\FamilyTree;
use App\Security\FamilyTreeVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\Serialize;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/trees/{treeId}/documents')]
final class DocumentController extends AbstractController
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly ObjectMapperInterface $mapper,
        private readonly TreeVersion $versions,
        private readonly RequestBodyMapper $requestBody,
    ) {
    }

    /** @return array<string, mixed> */
    #[Route('', name: 'api_documents_list', methods: ['GET'])]
    #[Serialize]
    #[IsGranted(FamilyTreeVoter::VIEW, subject: 'tree', statusCode: 404)]
    public function listing(Request $request, #[MapEntity(id: 'treeId')] FamilyTree $tree): array
    {
        $scope = $request->query->getString('scope', 'all');
        $limit = $request->query->getString('limit', '50');
        if (!ctype_digit($limit)) {
            throw new ApiException(422, 'DOCUMENT_QUERY_INVALID');
        }

        return $this->documents->listing(
            $tree,
            $request->query->get('personId'),
            $scope,
            $request->query->get('cursor'),
            (int)$limit,
            $request->query->getBoolean('newestFirst'),
        );
    }

    #[Route('', name: 'api_documents_upload', methods: ['POST'])]
    #[IsGranted(FamilyTreeVoter::EDIT, subject: 'tree', statusCode: 404)]
    public function upload(Request $request, #[MapEntity(id: 'treeId')] FamilyTree $tree): JsonResponse
    {
        $file = $request->files->get('file');
        $form = $request->request->all();
        $personId = $form['personId'] ?? null;
        if (null !== $personId && !is_string($personId)) {
            throw new ApiException(422, 'DOCUMENT_UPLOAD_INVALID');
        }
        $document = $this->documents->upload(
            $tree,
            is_string($personId) && '' !== $personId ? $personId : null,
            $file instanceof UploadedFile ? $file : null,
            $request->headers->get('If-Match'),
            $form['title'] ?? null,
            $form['description'] ?? null,
        );
        $etag = $this->versions->etag($tree);

        return $this->json(
            ['document' => $this->mapper->map($document, DocumentView::class), 'etag' => $etag],
            202,
            ['ETag' => $etag],
        );
    }

    #[Route('/{documentId}', name: 'api_documents_update', methods: ['PATCH'])]
    #[IsGranted(FamilyTreeVoter::EDIT, subject: 'tree', statusCode: 404)]
    public function update(
        Request $request,
        #[MapEntity(id: 'treeId')] FamilyTree $tree,
        string $documentId,
    ): JsonResponse {
        $input = $this->requestBody->decodeObject($request);
        $document = $this->documents->update(
            $tree,
            $documentId,
            $input,
            $request->headers->get('If-Match'),
        );
        $etag = $this->versions->etag($tree);

        return $this->json(
            ['document' => $this->mapper->map($document, DocumentView::class), 'etag' => $etag],
            headers: ['ETag' => $etag],
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
