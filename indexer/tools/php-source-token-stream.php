<?php
declare(strict_types=1);

/**
 * Yield the PHP lexical tokens needed by the repository's bounded source
 * contracts without depending on the optional tokenizer extension.
 *
 * Each token is `[kind, text]`. Punctuation uses an empty kind. Quoted and
 * heredoc contents remain one token, so braces or keywords inside them cannot
 * alter a caller's structural scan. Interpolated member calls and quote
 * boundaries that stop inside an expression are rejected; hiding executable
 * calls inside an opaque string would make source contracts fail open. The
 * repository contracts inspect pure-PHP files, so a closing PHP tag is rejected
 * rather than misclassifying subsequent inline HTML as code.
 *
 * @return Generator<int,array{0:string,1:string}>
 */
function wp_fts_php_source_token_stream(string $source, int $maxBytes = 4194304): Generator
{
    $length = strlen($source);
    if ($maxBytes < 1 || $length > $maxBytes) {
        throw new RuntimeException("PHP source exceeds the {$maxBytes}-byte lexical limit.");
    }

    for ($offset = 0; $offset < $length;) {
        $character = $source[$offset];

        if (wp_fts_php_source_is_space($character)) {
            $end = $offset + 1;
            while ($end < $length && wp_fts_php_source_is_space($source[$end])) {
                $end++;
            }
            yield ['whitespace', substr($source, $offset, $end - $offset)];
            $offset = $end;
            continue;
        }

        if ($character === '/' && $offset + 1 < $length && $source[$offset + 1] === '/') {
            $end = wp_fts_php_source_line_comment_end($source, $offset + 2);
            yield ['comment', substr($source, $offset, $end - $offset)];
            $offset = $end;
            continue;
        }
        if ($character === '#' && ($offset + 1 >= $length || $source[$offset + 1] !== '[')) {
            $end = wp_fts_php_source_line_comment_end($source, $offset + 1);
            yield ['comment', substr($source, $offset, $end - $offset)];
            $offset = $end;
            continue;
        }
        if ($character === '/' && $offset + 1 < $length && $source[$offset + 1] === '*') {
            $end = strpos($source, '*/', $offset + 2);
            if ($end === false) {
                throw new RuntimeException('PHP source contains an unterminated block comment.');
            }
            $end += 2;
            $kind = $offset + 2 < $length && $source[$offset + 2] === '*' ? 'doc_comment' : 'comment';
            yield [$kind, substr($source, $offset, $end - $offset)];
            $offset = $end;
            continue;
        }

        if ($character === '<' && substr($source, $offset, 3) === '<<<') {
            $heredocEnd = wp_fts_php_source_heredoc_end($source, $offset);
            if ($heredocEnd === null) {
                throw new RuntimeException('PHP source contains an invalid or unterminated heredoc.');
            }
            $literal = substr($source, $offset, $heredocEnd - $offset);
                wp_fts_php_source_assert_safe_opaque_literal($literal);
            yield ['string_literal', $literal];
            $offset = $heredocEnd;
            continue;
        }

        if ($character === "'" || $character === '"' || $character === '`') {
            $end = wp_fts_php_source_quoted_end($source, $offset, $character);
            $literal = substr($source, $offset, $end - $offset);
            wp_fts_php_source_assert_safe_opaque_literal($literal);
            yield ['string_literal', $literal];
            $offset = $end;
            continue;
        }

        if ($character === '$' && $offset + 1 < $length && wp_fts_php_source_is_identifier_start($source[$offset + 1])) {
            $end = $offset + 2;
            while ($end < $length && wp_fts_php_source_is_identifier_part($source[$end])) {
                $end++;
            }
            yield ['variable', substr($source, $offset, $end - $offset)];
            $offset = $end;
            continue;
        }

        if (wp_fts_php_source_is_identifier_start($character)) {
            $end = $offset + 1;
            while ($end < $length && wp_fts_php_source_is_identifier_part($source[$end])) {
                $end++;
            }
            $text = substr($source, $offset, $end - $offset);
            $lower = strtolower($text);
            $kind = match ($lower) {
                'function' => 'function',
                'if' => 'if',
                'throw' => 'throw',
                default => 'identifier',
            };
            yield [$kind, $text];
            $offset = $end;
            continue;
        }

        if ($character === '-' && $offset + 1 < $length && $source[$offset + 1] === '>') {
            yield ['object_operator', '->'];
            $offset += 2;
            continue;
        }
        if ($character === '?' && substr($source, $offset, 3) === '?->') {
            yield ['object_operator', '?->'];
            $offset += 3;
            continue;
        }
        if ($character === ':' && $offset + 1 < $length && $source[$offset + 1] === ':') {
            yield ['double_colon', '::'];
            $offset += 2;
            continue;
        }

        if ($character === '<' && substr($source, $offset, 5) === '<?php') {
            yield ['other', '<?php'];
            $offset += 5;
            continue;
        }
        if ($character === '<' && substr($source, $offset, 3) === '<?=') {
            yield ['other', '<?='];
            $offset += 3;
            continue;
        }
        if ($character === '?' && $offset + 1 < $length && $source[$offset + 1] === '>') {
            throw new RuntimeException('PHP source contracts do not accept closing PHP tags.');
        }

        yield ['', $character];
        $offset++;
    }
}

/**
 * Yield complete named function or method tokens without retaining the whole
 * source token stream in memory.
 *
 * @return Generator<int,array{name:string,tokens:array<int,array{0:string,1:string}>,source:string}>
 */
function wp_fts_php_source_function_stream(string $source, int $maxBytes = 4194304): Generator
{
    /** @var null|array{name:null|string,tokens:array<int,array{0:string,1:string}>} $pending */
    $pending = null;
    /** @var array<int,array{name:string,tokens:array<int,array{0:string,1:string}>,depth:int}> $active */
    $active = [];

    foreach (wp_fts_php_source_token_stream($source, $maxBytes) as $token) {
        [$kind, $text] = $token;
        $hadPendingFunction = $pending !== null;
        foreach ($active as &$frame) {
            $frame['tokens'][] = $token;
            if ($text === '{') {
                $frame['depth']++;
            } elseif ($text === '}') {
                $frame['depth']--;
            }
        }
        unset($frame);

        $completed = [];
        foreach ($active as $index => $frame) {
            if ($frame['depth'] === 0) {
                $completed[] = $frame;
                unset($active[$index]);
            }
        }
        if ($completed !== []) {
            $active = array_values($active);
            // Nested functions close before their containing function.
            foreach (array_reverse($completed) as $frame) {
                $functionSource = '';
                foreach ($frame['tokens'] as $bodyToken) {
                    $functionSource .= $bodyToken[1];
                }
                yield ['name' => $frame['name'], 'tokens' => $frame['tokens'], 'source' => $functionSource];
            }
        }

        if ($pending !== null) {
            $pending['tokens'][] = $token;
            if ($pending['name'] === null) {
                if (in_array($kind, ['identifier', 'if', 'throw', 'function'], true)) {
                    $pending['name'] = $text;
                } elseif ($text === '(') {
                    // This is an anonymous closure, not a named source contract.
                    $pending = null;
                }
            } elseif ($text === '{') {
                $active[] = [
                    'name' => $pending['name'],
                    'tokens' => $pending['tokens'],
                    'depth' => 1,
                ];
                $pending = null;
            } elseif ($text === ';') {
                // Interface and abstract declarations have no body to inspect.
                $pending = null;
            }
        }

        if ($kind === 'function' && !$hadPendingFunction) {
            $pending = ['name' => null, 'tokens' => [$token]];
        }
    }

    if ($pending !== null || $active !== []) {
        throw new RuntimeException('PHP source ends inside an incomplete named function.');
    }
}

/** Whether a normalized PHP source token is whitespace or a comment. */
function wp_fts_php_source_token_is_trivia(array $token): bool
{
    return in_array($token[0] ?? '', ['whitespace', 'comment', 'doc_comment'], true);
}

/** Reject executable interpolation that an opaque string token would hide. */
function wp_fts_php_source_assert_safe_opaque_literal(string $literal): void
{
    if (
        !wp_fts_php_source_literal_can_interpolate($literal)
        || !str_contains($literal, '$')
    ) {
        return;
    }
    if (
        wp_fts_php_source_braced_interpolations_are_closed($literal)
        && !wp_fts_php_source_literal_contains_member_call($literal)
    ) {
        return;
    }

    throw new RuntimeException('PHP source contains executable string interpolation that cannot remain opaque.');
}

/** Ensure the chosen quote boundary did not stop inside a braced expression. */
function wp_fts_php_source_braced_interpolations_are_closed(string $literal): bool
{
    $length = strlen($literal);
    for ($offset = 0; $offset + 1 < $length; $offset++) {
        if (
            !($literal[$offset] === '{' && $literal[$offset + 1] === '$')
            && !($literal[$offset] === '$' && $literal[$offset + 1] === '{')
        ) {
            continue;
        }
        $end = strpos($literal, '}', $offset + 2);
        if ($end === false) {
            return false;
        }
        $offset = $end;
    }

    return true;
}

/** Find a literal or dynamic member immediately invoked inside a string token. */
function wp_fts_php_source_literal_contains_member_call(string $literal): bool
{
    $length = strlen($literal);
    for ($offset = 0; $offset < $length; $offset++) {
        $operatorBytes = 0;
        if (substr($literal, $offset, 3) === '?->') {
            $operatorBytes = 3;
        } elseif (substr($literal, $offset, 2) === '->' || substr($literal, $offset, 2) === '::') {
            $operatorBytes = 2;
        }
        if ($operatorBytes === 0) {
            continue;
        }

        $cursor = wp_fts_php_source_skip_trivia($literal, $offset + $operatorBytes);
        if ($cursor >= $length) {
            continue;
        }

        if (wp_fts_php_source_is_identifier_start($literal[$cursor])) {
            $cursor++;
            while ($cursor < $length && wp_fts_php_source_is_identifier_part($literal[$cursor])) {
                $cursor++;
            }
        } elseif ($literal[$cursor] === '$' || $literal[$cursor] === '{') {
            // A dynamic member may be invoked after an arbitrarily nested PHP
            // expression. Treat it as executable rather than approximating a
            // second parser inside the already bounded string token.
            return true;
        } else {
            continue;
        }

        $cursor = wp_fts_php_source_skip_trivia($literal, $cursor);
        if (($literal[$cursor] ?? '') === '(') {
            return true;
        }
    }

    return false;
}

/** Skip PHP whitespace and comments inside one interpolation expression. */
function wp_fts_php_source_skip_trivia(string $source, int $offset): int
{
    $length = strlen($source);
    while ($offset < $length) {
        if (wp_fts_php_source_is_space($source[$offset])) {
            $offset++;
            continue;
        }
        if ($source[$offset] === '/' && ($source[$offset + 1] ?? '') === '*') {
            $end = strpos($source, '*/', $offset + 2);
            if ($end === false) {
                return $length;
            }
            $offset = $end + 2;
            continue;
        }
        if (
            ($source[$offset] === '/' && ($source[$offset + 1] ?? '') === '/')
            || $source[$offset] === '#'
        ) {
            $end = wp_fts_php_source_line_comment_end($source, $offset + 1);
            if ($end >= $length) {
                return $length;
            }
            $offset = $end;
            continue;
        }
        break;
    }

    return $offset;
}

/** Return the first CR, LF, or PHP closing tag that terminates a line comment. */
function wp_fts_php_source_line_comment_end(string $source, int $offset): int
{
    $length = strlen($source);
    for ($cursor = $offset; $cursor < $length; $cursor++) {
        if ($source[$cursor] === "\r" || $source[$cursor] === "\n") {
            return $cursor;
        }
        if ($source[$cursor] === '?' && ($source[$cursor + 1] ?? '') === '>') {
            return $cursor;
        }
    }

    return $length;
}

/** Whether one already-delimited PHP string token supports interpolation. */
function wp_fts_php_source_literal_can_interpolate(string $literal): bool
{
    $first = $literal[0] ?? '';
    if ($first === '"' || $first === '`') {
        return true;
    }
    if (!str_starts_with($literal, '<<<')) {
        return false;
    }

    $headerEnd = strpos($literal, "\n", 3);
    if ($headerEnd === false) {
        return false;
    }
    $header = ltrim(substr($literal, 3, $headerEnd - 3));

    return ($header[0] ?? '') !== "'";
}

/** Return the exclusive end offset of one quoted PHP lexical token. */
function wp_fts_php_source_quoted_end(string $source, int $offset, string $quote): int
{
    $length = strlen($source);
    for ($cursor = $offset + 1; $cursor < $length; $cursor++) {
        if ($source[$cursor] === '\\') {
            $cursor++;
            continue;
        }
        if ($source[$cursor] === $quote) {
            return $cursor + 1;
        }
    }

    throw new RuntimeException('PHP source contains an unterminated quoted string.');
}

/** Return the exclusive end offset of a valid heredoc/nowdoc, if present. */
function wp_fts_php_source_heredoc_end(string $source, int $offset): ?int
{
    $length = strlen($source);
    $headerEnd = strpos($source, "\n", $offset + 3);
    if ($headerEnd === false) {
        return null;
    }
    $header = trim(substr($source, $offset + 3, $headerEnd - ($offset + 3)));
    if ($header === '') {
        return null;
    }
    if (($header[0] === "'" || $header[0] === '"') && strlen($header) >= 2) {
        $quote = $header[0];
        if ($header[strlen($header) - 1] !== $quote) {
            return null;
        }
        $label = substr($header, 1, -1);
    } else {
        $label = $header;
    }
    if ($label === '' || !wp_fts_php_source_is_identifier_start($label[0])) {
        return null;
    }
    for ($index = 1, $labelLength = strlen($label); $index < $labelLength; $index++) {
        if (!wp_fts_php_source_is_identifier_part($label[$index])) {
            return null;
        }
    }

    for ($lineStart = $headerEnd + 1; $lineStart < $length;) {
        $lineEnd = strpos($source, "\n", $lineStart);
        $lineEnd = $lineEnd === false ? $length : $lineEnd + 1;
        $contentStart = $lineStart;
        while ($contentStart < $lineEnd && ($source[$contentStart] === ' ' || $source[$contentStart] === "\t")) {
            $contentStart++;
        }
        if (substr($source, $contentStart, strlen($label)) === $label) {
            $after = $contentStart + strlen($label);
            if ($after === $length || !wp_fts_php_source_is_identifier_part($source[$after])) {
                return $after;
            }
        }
        $lineStart = $lineEnd;
    }

    return null;
}

/** ASCII PHP whitespace accepted by the lexical scanner. */
function wp_fts_php_source_is_space(string $character): bool
{
    return $character === ' '
        || $character === "\t"
        || $character === "\n"
        || $character === "\r"
        || $character === "\0"
        || $character === "\v"
        || $character === "\f";
}

/** PHP identifier start byte; non-ASCII bytes are retained like token_get_all. */
function wp_fts_php_source_is_identifier_start(string $character): bool
{
    $ord = ord($character);
    return $character === '_'
        || ($character >= 'A' && $character <= 'Z')
        || ($character >= 'a' && $character <= 'z')
        || $ord >= 128;
}

/** PHP identifier continuation byte. */
function wp_fts_php_source_is_identifier_part(string $character): bool
{
    return wp_fts_php_source_is_identifier_start($character)
        || ($character >= '0' && $character <= '9');
}
