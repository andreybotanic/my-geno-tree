<?php

namespace App\Application\TreeMerge\Ai;

use App\Api\ApiException;

final readonly class MergeAiRepair
{
    public function __construct(public MergeAiResult $previous, public ApiException $error)
    {
    }

    /** @return array<string, mixed> */
    public function feedback(): array
    {
        return [
            'code'    => $this->error->getErrorCode(),
            'fields'  => $this->error->getFields(),
            'details' => $this->error->getDetails(),
        ];
    }
}
