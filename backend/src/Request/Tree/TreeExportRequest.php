<?php

namespace App\Request\Tree;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class TreeExportRequest
{
    public function __construct(
        #[Assert\Choice(choices: ['gedcom-5.5.1', 'gedcom-7'], message: 'tree_export_format_invalid')]
        public string $format,
        #[Assert\NotBlank(message: 'tree_export_file_name_required')]
        public string $fileName,
    ) {
    }
}
