<?php
declare(strict_types=1);

final class WP_FTS_PostingsCodec
{
    /**
     * @param array<int,int> $postings doc_id => weighted_tf
     */
    public static function encode(array $postings): string
    {
        ksort($postings, SORT_NUMERIC);

        $blob = self::encode_varint(count($postings));
        $previousDocId = 0;

        foreach ($postings as $docId => $weightedTf) {
            $docId = (int) $docId;
            if ($docId < $previousDocId) {
                throw new InvalidArgumentException('Postings must be sorted by ascending doc_id.');
            }

            $blob .= self::encode_varint($docId - $previousDocId);
            $blob .= self::encode_varint(max(1, (int) $weightedTf));
            $previousDocId = $docId;
        }

        return $blob;
    }

    /**
     * @return array<int,int> doc_id => weighted_tf
     */
    public static function decode(string $blob): array
    {
        $offset = 0;
        $count = self::decode_varint($blob, $offset);
        $docId = 0;
        $postings = [];

        for ($i = 0; $i < $count; $i++) {
            $docId += self::decode_varint($blob, $offset);
            $postings[$docId] = max(1, self::decode_varint($blob, $offset));
        }

        if ($offset !== strlen($blob)) {
            throw new UnexpectedValueException('Posting blob has trailing bytes.');
        }

        return $postings;
    }

    public static function encode_varint(int $value): string
    {
        if ($value < 0) {
            throw new InvalidArgumentException('Varint cannot encode negative values.');
        }

        $bytes = '';
        do {
            $byte = $value & 0x7f;
            $value >>= 7;
            if ($value !== 0) {
                $byte |= 0x80;
            }
            $bytes .= chr($byte);
        } while ($value !== 0);

        return $bytes;
    }

    public static function decode_varint(string $data, int &$offset): int
    {
        $result = 0;
        $shift = 0;
        $length = strlen($data);

        while ($offset < $length) {
            $byte = ord($data[$offset]);
            $offset++;

            $result |= (($byte & 0x7f) << $shift);
            if (($byte & 0x80) === 0) {
                return $result;
            }

            $shift += 7;
            if ($shift > 63) {
                throw new UnexpectedValueException('Varint is too large for a PHP integer.');
            }
        }

        throw new UnexpectedValueException('Truncated varint.');
    }
}
