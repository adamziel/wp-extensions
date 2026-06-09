<?php
declare(strict_types=1);

/**
 * Default tokenizer adapter for the existing Unicode letter/number behavior.
 */
final class Language_FTS_Playground_Unicode_Words_Tokenizer
{
    public const ID = 'unicode_words_v1';
    public const TYPE = 'unicode_words';

    /**
     * @return array{id:string,type:string,resources:array<string,string>,capabilities:array{emits_offsets:bool,emits_positions:bool,supports_fuzzy:bool,supports_overlaps:bool}}
     */
    public static function default_contract(): array
    {
        return [
            'id' => self::ID,
            'type' => self::TYPE,
            'resources' => [],
            'capabilities' => [
                'emits_offsets' => true,
                'emits_positions' => true,
                'supports_fuzzy' => true,
                'supports_overlaps' => false,
            ],
        ];
    }

    /**
     * @return array<int,array{surface:string,start_byte:int,end_byte:int,type:string}>
     */
    public function tokenize(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $matches = [];
        $match_count = preg_match_all('/[\p{L}\p{N}]+/u', $text, $matches, PREG_OFFSET_CAPTURE);
        if ($match_count === false || $match_count === 0) {
            return [];
        }

        $tokens = [];
        foreach ($matches[0] as $match) {
            $surface = (string) ($match[0] ?? '');
            if ($surface === '') {
                continue;
            }

            $start = (int) ($match[1] ?? 0);
            $tokens[] = [
                'surface' => $surface,
                'start_byte' => $start,
                'end_byte' => $start + strlen($surface),
                'type' => preg_match('/^\p{N}+$/u', $surface) === 1 ? 'number' : 'word',
            ];
        }

        return $tokens;
    }
}
