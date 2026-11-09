<?php

namespace App\Controller\Api\Tree;

use App\Api\ApiException;
use App\Api\TreeViewReader;
use App\Application\Tree\TreeVersion;
use App\Application\TreeExchange\TreeImportService;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class TreeImportController extends AbstractController
{
    public function __construct(
        private readonly TreeImportService $imports,
        private readonly TreeViewReader $views,
        private readonly TreeVersion $versions,
    ) {
    }

    #[Route('/api/v1/tree-imports/inspect', name: 'api_tree_imports_inspect', methods: ['POST'])]
    public function inspect(Request $request): JsonResponse
    {
        return new JsonResponse($this->imports->inspect($this->uploadedFile($request)));
    }

    #[Route('/api/v1/tree-imports', name: 'api_tree_imports_create', methods: ['POST'])]
    public function import(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $name = $request->request->get('name');
        if (!is_string($name)) {
            throw new ApiException(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'VALIDATION_FAILED', 'validation_failed', ['name' => ['tree_name_required']]);
        }
        $result = $this->imports->import(
            $user,
            $name,
            $this->uploadedFile($request),
            $this->recognizePlaces($request),
        );

        return $this->json(
            [
                'tree'     => $this->views->summary($result->tree, $result->personCount),
                'warnings' => $result->warnings,
            ],
            JsonResponse::HTTP_CREATED,
            [
                'ETag'     => $this->versions->etag($result->tree),
                'Location' => sprintf('/api/v1/trees/%s/snapshot', $result->tree->getId()->toRfc4122()),
            ],
        );
    }

    private function uploadedFile(Request $request): UploadedFile
    {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw new ApiException(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'TREE_IMPORT_FILE_REQUIRED', 'tree_import_file_required', ['file' => ['tree_import_file_required']]);
        }

        return $file;
    }

    private function recognizePlaces(Request $request): bool
    {
        $value = $request->request->get('recognizePlaces', false);
        $recognized = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if (null === $recognized) {
            throw new ApiException(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'VALIDATION_FAILED', 'validation_failed', ['recognizePlaces' => ['boolean_required']]);
        }

        return $recognized;
    }
}
