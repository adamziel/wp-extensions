<?php
declare(strict_types=1);

/**
 * Encodes posting lists as compact delta-compressed varint blobs.
 *
 * The codec stores a count, then `(doc_id_delta, weighted_tf)` pairs. Document
 * ids are sorted and delta-encoded to keep postings small while preserving fast
 * full-list decoding for the current searcher.
 */
final class WP_FTS_PostingsCodec
{
    /**
     * Encode a document-frequency map into the storage blob format.
     *
     * `$postings` is sorted by numeric document id before encoding. Weighted
     * term frequencies are clamped to at least 1 because a present posting must
     * contribute a positive frequency to BM25.
     *
     * @param array<int,int> $postings doc_id => weighted_tf
     * @return string Binary blob containing varint count and delta postings.
     * @throws InvalidArgumentException If a negative document-id delta would be
     *         encoded.
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
     * Decode a storage blob into a document-frequency map.
     *
     * The method reconstructs absolute document ids by summing deltas. It
     * rejects blobs with trailing bytes so corrupt or version-mismatched data is
     * noticed instead of silently influencing scores.
     *
     * @param string $blob Binary value produced by `encode()`.
     * @return array<int,int> doc_id => weighted_tf
     * @throws UnexpectedValueException If a varint is truncated, too large, or
     *         the blob has trailing bytes.
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

    /**
     * Encode a non-negative integer as little-endian base-128 varint bytes.
     *
     * @param int $value Integer to encode. Negative values are invalid.
     * @return string Binary varint representation.
     * @throws InvalidArgumentException If `$value` is negative.
     */
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

    /**
     * Decode one varint from `$data` and advance `$offset` past it.
     *
     * Callers pass the offset by reference so decode loops can read a sequence
     * without slicing binary strings.
     *
     * @param string $data Binary buffer containing at least one varint at
     *        `$offset`.
     * @param int $offset Byte offset to read from; updated to the first byte
     *        after the decoded varint.
     * @return int Decoded non-negative integer.
     * @throws UnexpectedValueException If the varint is truncated or too large
     *         for the current PHP integer size.
     */
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
