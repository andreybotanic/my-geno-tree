<?php

namespace App\Api;

use App\Entity\Value\KnownText;

final readonly class KnownTextViewFactory
{
    /**
     * @return array{state: string, value?: string}
     */
    public function create(KnownText $text): array
    {
        if (!$text->isKnown()) {
            return ['state' => 'unknown'];
        }

        return [
            'state' => 'known',
            'value' => (string)$text->getValue(),
        ];
    }
}
