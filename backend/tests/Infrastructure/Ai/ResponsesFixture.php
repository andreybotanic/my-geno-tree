<?php

namespace App\Tests\Infrastructure\Ai;

use Symfony\Component\HttpClient\Response\MockResponse;

final class ResponsesFixture
{
    /** @param list<array<string, mixed>> $items */
    public static function stream(array $items, int $usage = 110): MockResponse
    {
        $events = [];
        foreach ($items as $index => $item) {
            $events[] = ['type' => 'response.output_item.added', 'output_index' => $index, 'item' => $item];
            if ('message' === $item['type']) {
                /** @var list<array{text: string}> $content */
                $content = $item['content'];
                foreach ($content as $part) {
                    foreach (mb_str_split($part['text'], 4) as $text) {
                        $events[] = [
                            'type'    => 'response.output_text.delta',
                            'item_id' => $item['id'],
                            'delta'   => $text,
                        ];
                    }
                }
            }
            $events[] = ['type' => 'response.output_item.done', 'item' => $item];
        }
        $events[] = [
            'type'     => 'response.completed',
            'response' => [
                'status' => 'completed',
                'output' => $items,
                'usage'  => ['input_tokens' => max(0, $usage - 10), 'output_tokens' => 10, 'total_tokens' => $usage],
            ],
        ];

        return new MockResponse(
            implode(
                '',
                array_map(
                    static fn (array $event): string => 'data: ' . json_encode($event, JSON_THROW_ON_ERROR) . "\n\n",
                    $events,
                ),
            ),
            ['response_headers' => ['content-type: text/event-stream']],
        );
    }

    public static function text(string $text, int $usage = 110): MockResponse
    {
        return self::stream(
            [
                [
                    'id'      => 'answer',
                    'type'    => 'message',
                    'role'    => 'assistant',
                    'phase'   => 'final_answer',
                    'content' => [['type' => 'output_text', 'text' => $text]],
                ],
            ],
            $usage,
        );
    }

    /** @param array<string, mixed> $arguments */
    public static function tool(string $name, array $arguments): MockResponse
    {
        return self::stream(
            [
                [
                    'id'        => 'item_' . $name,
                    'type'      => 'function_call',
                    'call_id'   => 'call_' . $name,
                    'name'      => $name,
                    'arguments' => json_encode($arguments, JSON_THROW_ON_ERROR),
                ],
            ],
        );
    }

    /** Read semantic messages independently of the wire representation.
     * @param array<string, mixed> $body
     *
     * @return list<array{role: string, content: string}>
     */
    public static function messages(array $body): array
    {
        $messages = [
            [
                'role'    => 'system',
                'content' => is_string($body['instructions'] ?? null) ? $body['instructions'] : '',
            ],
        ];
        /** @var list<array{
         * type?: string, role?: string, output?: string, content?: string|list<array{text: string}>
         * }> $items */
        $items = $body['input'];
        foreach ($items as $item) {
            if ('function_call_output' === ($item['type'] ?? null)) {
                $messages[] = ['role' => 'tool', 'content' => $item['output'] ?? ''];
            } elseif (isset($item['role'])) {
                $content = $item['content'] ?? '';
                $messages[] = [
                    'role'    => $item['role'],
                    'content' => is_string($content) ? $content : implode('', array_column($content, 'text')),
                ];
            }
        }

        return $messages;
    }
}
