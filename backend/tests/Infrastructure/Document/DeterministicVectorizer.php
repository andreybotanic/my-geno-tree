<?php

namespace App\Tests\Infrastructure\Document;

use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\DocumentInterface;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\Document\VectorDocumentInterface;
use Symfony\AI\Store\Document\VectorizerInterface;

final class DeterministicVectorizer implements VectorizerInterface
{
    public function vectorize(
        string|\Stringable|DocumentInterface|array $values,
        array $options = [],
    ): Vector|VectorDocumentInterface|array {
        if (is_array($values)) {
            return array_map(fn ($value) => $this->vectorize($value), $values);
        }
        $data = array_fill(0, 1024, 0.0);
        $content = $values instanceof DocumentInterface ? $values->getContent() : $values;
        $text = is_string($content) ? $content : '';
        $data[0] = 1.0;
        foreach (['Irkutsk', 'telegraph'] as $index => $term) {
            $data[$index + 1] = str_contains($text, $term) ? 1.0 : 0.0;
        }
        $vector = new Vector($data);

        return $values instanceof DocumentInterface
            ? new VectorDocument($values->getId(), $vector, $values->getMetadata())
            : $vector;
    }
}
