<?php

namespace App\Application\TreeExchange;

use App\Api\ApiException;
use App\Entity\FamilyTree;
use App\Repository\GenealogicalEventRepository;
use App\Repository\MarriagePeriodRepository;
use App\Repository\ParentLinkRepository;
use App\Repository\PersonRepository;
use Symfony\Component\HttpFoundation\JsonResponse;

final readonly class TreeExportService
{
    public function __construct(
        private PersonRepository $people,
        private ParentLinkRepository $parentLinks,
        private MarriagePeriodRepository $marriages,
        private GenealogicalEventRepository $events,
        private GedcomWriter $writer,
    ) {
    }

    public function export(FamilyTree $tree, string $format, string $requestedFileName): TreeExport
    {
        $gedcomFormat = GedcomFormat::tryFrom($format);
        if (null === $gedcomFormat) {
            throw new ApiException(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'TREE_EXPORT_FORMAT_UNSUPPORTED', 'tree_export_format_unsupported');
        }
        $fileName = $this->fileName($requestedFileName);
        $contents = $this->writer->write(
            $gedcomFormat,
            $fileName,
            $this->people->findAllForTree($tree),
            $this->parentLinks->findAllForTree($tree),
            $this->marriages->findAllForTree($tree),
            $this->events->findAllForTree($tree),
        );

        return new TreeExport($contents, $fileName);
    }

    private function fileName(string $fileName): string
    {
        $fileName = trim($fileName);
        if (
            '' === trim($fileName, ' .')
            || mb_strlen($fileName) > 150
            || 1 === preg_match('/[\\x00-\\x1F\\x7F\\\\\/:*?"<>|]/u', $fileName)
            || str_ends_with($fileName, '.')
        ) {
            throw new ApiException(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'VALIDATION_FAILED', 'validation_failed', ['fileName' => ['tree_export_file_name_invalid']]);
        }

        return $fileName;
    }
}
