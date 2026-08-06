<?php

namespace App\Controller\Api\Tree;

use App\Api\RequestBodyMapper;
use App\Application\TreeExchange\TreeExportService;
use App\Entity\FamilyTree;
use App\Request\Tree\TreeExportRequest;
use App\Security\FamilyTreeVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final readonly class TreeExportController
{
    public function __construct(
        private RequestBodyMapper $requestBody,
        private TreeExportService $exports,
    ) {
    }

    #[Route('/api/v1/trees/{treeId}/exports', name: 'api_tree_exports_create', methods: ['POST'])]
    #[IsGranted(
        FamilyTreeVoter::VIEW,
        subject: 'tree',
        statusCode: JsonResponse::HTTP_NOT_FOUND,
    )]
    public function __invoke(
        Request $request,
        #[MapEntity(id: 'treeId')] FamilyTree $tree,
    ): Response {
        $input = $this->requestBody->map($request, TreeExportRequest::class);
        $export = $this->exports->export($tree, $input->format, $input->fileName);
        $response = new Response($export->contents);
        $response->headers->set('Content-Type', 'application/x-gedcom; charset=UTF-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $export->fileName . '.ged',
            'family-tree.ged',
        ));

        return $response;
    }
}
