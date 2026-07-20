<?php
declare(strict_types=1);

test_case('PHP source token stream isolates structure from quoted heredoc and commented decoys', function (): void {
    $source = <<<'PHP'
<?php
// function commented_decoy() { throw new RuntimeException(); }
function actual_contract(): void {
    $quoted = "function quoted_decoy() { }";
    $heredoc = <<<TEXT
function heredoc_decoy() { throw new RuntimeException(); }
TEXT;
    $object->enqueue();
    Hidden::claim();
    function nested_contract(): void { throw new RuntimeException(); }
}
PHP;

    $functions = [];
    foreach (wp_fts_php_source_function_stream($source, strlen($source)) as $function) {
        $functions[$function['name']] = $function['source'];
    }

    assert_same(['nested_contract', 'actual_contract'], array_keys($functions), 'the stream should expose real nested and containing functions in close order');
    assert_contains('$object->enqueue();', $functions['actual_contract'], 'the containing function should retain executable object calls');
    assert_contains('Hidden::claim();', $functions['actual_contract'], 'the containing function should retain executable static calls');
    assert_true(!isset($functions['commented_decoy'], $functions['quoted_decoy'], $functions['heredoc_decoy']), 'comments and string contents must never invent PHP functions');
});

test_case('PHP source token stream classifies executable operators and first-work keywords', function (): void {
    $source = <<<'PHP'
<?php
function guarded() {
    /* $decoy->enqueue(); Hidden::claim(); */
    if (true) {
        $worker->enqueue();
        Hidden::claim();
        throw new RuntimeException();
    }
}
PHP;
    $kinds = [];
    foreach (wp_fts_php_source_token_stream($source, strlen($source)) as $token) {
        if (!wp_fts_php_source_token_is_trivia($token)) {
            $kinds[] = $token[0];
        }
    }

    assert_same(1, count(array_keys($kinds, 'object_operator', true)), 'only the executable object operator should be classified');
    assert_same(1, count(array_keys($kinds, 'double_colon', true)), 'only the executable static operator should be classified');
    assert_true(in_array('if', $kinds, true), 'the stream should classify an executable if guard');
    assert_true(in_array('throw', $kinds, true), 'the stream should classify an executable throw');
});

test_case('PHP source token stream rejects its configured byte boundary plus one', function (): void {
    $source = '<?php function bounded(): void {}';
    iterator_to_array(wp_fts_php_source_token_stream($source, strlen($source)));

    $rejected = false;
    try {
        iterator_to_array(wp_fts_php_source_token_stream($source . ' ', strlen($source)));
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'lexical limit');
    }
    assert_true($rejected, 'the lexical stream should reject max+1 before token allocation');
});

test_case('PHP source token stream fails closed on executable string interpolation', function (): void {
    $sources = [
        '<?php function quoted() { return "{$queue->enqueue()}"; }',
        "<?php function heredoc() { return <<<TEXT\n{\$queue?->enqueue()}\nTEXT;\n}",
        '<?php function command() { return `echo {$queue::claim()}`; }',
        '<?php function trivia() { return "{$queue /* owner */ -> /* mutation */ enqueue()}"; }',
        '<?php function carriage_return() { return "{$queue->// owner' . "\r" . 'enqueue()}"; }',
        '<?php function dynamic() { return "{$queue->{$method}()}"; }',
        '<?php function keyed() { return "{$queues["primary"]->enqueue()}"; }',
        '<?php function quoted_comment() { return "{$queue->// hidden quotes " "' . "\n" . 'enqueue()}"; }',
    ];
    foreach ($sources as $source) {
        $rejected = false;
        try {
            iterator_to_array(wp_fts_php_source_function_stream($source, strlen($source)));
        } catch (RuntimeException $error) {
            $rejected = str_contains($error->getMessage(), 'executable string interpolation that cannot remain opaque');
        }
        assert_true($rejected, 'opaque executable interpolation must not hide a queue mutation from a source contract');
    }

    $safe = '<?php function sql() { return "SELECT * FROM {$table} WHERE id = 1"; }';
    $functions = iterator_to_array(wp_fts_php_source_function_stream($safe, strlen($safe)));
    assert_same('sql', $functions[0]['name'] ?? null, 'ordinary scalar interpolation should remain available to bounded structural scans');

    $property = '<?php function sql_table() { return "SELECT * FROM {$wpdb->term_taxonomy}"; }';
    $functions = iterator_to_array(wp_fts_php_source_function_stream($property, strlen($property)));
    assert_same('sql_table', $functions[0]['name'] ?? null, 'interpolated property reads should not be mistaken for executable method calls');
});

test_case('PHP source function stream preserves flexible heredoc delimiters', function (): void {
    $source = <<<'PHP'
<?php
function flexible_heredoc(): array {
    return [
        <<<TEXT
payload
TEXT,
        'tail',
    ];
}
function after_flexible_heredoc(): void {}
PHP;

    $names = [];
    foreach (wp_fts_php_source_function_stream($source, strlen($source)) as $function) {
        $names[] = $function['name'];
    }
    assert_same(['flexible_heredoc', 'after_flexible_heredoc'], $names, 'a comma after the closing label must remain structural PHP rather than heredoc text');

    if (function_exists('token_get_all')) {
        $nativeNames = [];
        $tokens = token_get_all($source);
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }
            for ($cursor = $index + 1, $count = count($tokens); $cursor < $count; $cursor++) {
                if (is_array($tokens[$cursor]) && $tokens[$cursor][0] === T_STRING) {
                    $nativeNames[] = $tokens[$cursor][1];
                    break;
                }
                if ($tokens[$cursor] === '(') {
                    break;
                }
            }
        }
        assert_same($nativeNames, $names, 'the flexible-heredoc function inventory should match ext-tokenizer');
    } else {
        assert_true(true, 'the no-extension lane retains the explicit flexible-heredoc inventory');
    }
});

test_case('PHP source function stream fails closed on mixed inline-HTML templates', function (): void {
    $source = <<<'PHP'
<?php
function actual($queue) {
?>
}
<?php
    $queue->enqueue();
}
PHP;

    $rejected = false;
    try {
        iterator_to_array(wp_fts_php_source_function_stream($source, strlen($source)));
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'closing PHP tags');
    }
    assert_true($rejected, 'inline HTML must not hide executable calls by changing the fallback function depth');
});

test_case('PHP source function stream agrees with ext-tokenizer on adversarial named functions and calls', function (): void {
    $source = <<<'PHP'
<?php
#[Example('function attribute_decoy() {}')]
final class Probe {
    public function if(): void {}
    public function throw(): void {}
    public function function(): void {}
    public function &attributed_method(): mixed {
        $quoted = 'function quoted_decoy() { $x->enqueue(); }';
        $closure = static function () use ($quoted): void {
            $worker?->enqueue();
            Hidden::claim();
            function nested_named(): void {}
        };
        return $closure;
    }
}
PHP;
    $streamNames = [];
    $streamCalls = [];
    foreach (wp_fts_php_source_function_stream($source, strlen($source)) as $function) {
        $streamNames[] = $function['name'];
    }
    $streamTokens = iterator_to_array(wp_fts_php_source_token_stream($source, strlen($source)));
    foreach ($streamTokens as $index => $token) {
        if (!in_array($token[0], ['object_operator', 'double_colon'], true)) {
            continue;
        }
        for ($cursor = $index + 1, $count = count($streamTokens); $cursor < $count; $cursor++) {
            if (wp_fts_php_source_token_is_trivia($streamTokens[$cursor])) {
                continue;
            }
            if ($streamTokens[$cursor][0] === 'identifier') {
                $streamCalls[] = $streamTokens[$cursor][1];
            }
            break;
        }
    }
    sort($streamNames, SORT_STRING);
    sort($streamCalls, SORT_STRING);
    assert_same(['attributed_method', 'function', 'if', 'nested_named', 'throw'], $streamNames, 'attributes, reserved method names, references, and nested closures should retain exactly the real named functions');
    assert_same(['claim', 'enqueue'], $streamCalls, 'quoted calls must not join executable object and static calls');

    if (function_exists('token_get_all')) {
        $nativeTokens = token_get_all($source);
        $nativeNames = [];
        $nativeCalls = [];
        for ($index = 0, $count = count($nativeTokens); $index < $count; $index++) {
            if (is_array($nativeTokens[$index]) && $nativeTokens[$index][0] === T_FUNCTION) {
                for ($cursor = $index + 1; $cursor < $count; $cursor++) {
                    if (wp_fts_test_native_token_is_identifier($nativeTokens[$cursor])) {
                        $nativeNames[] = $nativeTokens[$cursor][1];
                        break;
                    }
                    if ($nativeTokens[$cursor] === '(') {
                        break;
                    }
                }
            }
            if (!is_array($nativeTokens[$index]) || !in_array($nativeTokens[$index][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                continue;
            }
            for ($cursor = $index + 1; $cursor < $count; $cursor++) {
                if (is_array($nativeTokens[$cursor]) && in_array($nativeTokens[$cursor][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if (is_array($nativeTokens[$cursor]) && $nativeTokens[$cursor][0] === T_STRING) {
                    $nativeCalls[] = $nativeTokens[$cursor][1];
                }
                break;
            }
        }
        sort($nativeNames, SORT_STRING);
        sort($nativeCalls, SORT_STRING);
        assert_same($nativeNames, $streamNames, 'the fallback named-function multiset should match ext-tokenizer');
        assert_same($nativeCalls, $streamCalls, 'the fallback executable-call multiset should match ext-tokenizer');
    } else {
        assert_true(true, 'the no-extension lane exercises the same explicit fallback expectations without ext-tokenizer');
    }
});

test_case('PHP source token stream fails closed on unclosed lexical structures', function (): void {
    $unclosedString = "<?php function complete(): void {} \$value = 'function string_decoy() {}";
    $unclosedComment = '<?php function also_complete(): void {} /* function comment_decoy() {}';
    $unclosedBody = '<?php function incomplete(): void { if (true) {';
    $unclosedHeredoc = "<?php function incomplete_heredoc() { return <<<TEXT\npayload\n";
    $incompleteDeclaration = '<?php function incomplete_declaration(';

    foreach ([$unclosedString, $unclosedComment, $unclosedBody, $unclosedHeredoc, $incompleteDeclaration] as $source) {
        $rejected = false;
        try {
            iterator_to_array(wp_fts_php_source_function_stream($source, strlen($source)));
        } catch (RuntimeException $error) {
            $rejected = str_contains($error->getMessage(), 'unterminated')
                || str_contains($error->getMessage(), 'incomplete');
        }
        assert_true($rejected, 'an incomplete lexical or function structure must fail the complete source inventory closed');
    }
});

test_case('PHP source stream matches ext-tokenizer on the complete plugin method and call inventory', function (): void {
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Plugin.php');
    $streamNames = [];
    foreach (wp_fts_php_source_function_stream($source) as $function) {
        $streamNames[] = $function['name'];
    }
    sort($streamNames, SORT_STRING);
    assert_true(count($streamNames) >= 800, 'the fallback comparison must cover the complete large plugin source');
    assert_true(in_array('flush_foreground_bulk_mutations', $streamNames, true), 'the fallback inventory should retain a known queue mutation caller');

    $queueMutationNames = array_fill_keys([
        'enqueue', 'enqueue_many', 'enqueue_scope', 'fence_post', 'fence_scope',
        'promote_post', 'promote_scope', 'handoff_foreground_mutation_scope',
        'coalesce_corpus_successor', 'retry_many', 'claim_batch',
        'fail_scope', 'fail_many', 'release_scope', 'release_many',
        'discard_replaced_scope', 'yield_scope_and_release_posts',
        'acknowledge_scope', 'acknowledge_many', 'enqueue_corpus_scope',
    ], true);
    $streamCalls = [];
    $pendingOperator = null;
    foreach (wp_fts_php_source_token_stream($source) as $token) {
        if ($pendingOperator !== null) {
            if (wp_fts_php_source_token_is_trivia($token)) {
                continue;
            }
            if ($token[0] === 'identifier' && isset($queueMutationNames[$token[1]])) {
                $streamCalls[] = ($pendingOperator === 'double_colon' ? 'static:' : 'object:') . $token[1];
            }
            $pendingOperator = null;
        }
        if (in_array($token[0], ['object_operator', 'double_colon'], true)) {
            $pendingOperator = $token[0];
        }
    }
    sort($streamCalls, SORT_STRING);

    if (!function_exists('token_get_all')) {
        assert_true(count($streamCalls) >= 30, 'the no-extension fallback should retain the complete large queue-mutation call inventory');
        return;
    }

    $nativeTokens = token_get_all($source);
    $nativeNames = [];
    $nativeCalls = [];
    for ($index = 0, $count = count($nativeTokens); $index < $count; $index++) {
        if (is_array($nativeTokens[$index]) && $nativeTokens[$index][0] === T_FUNCTION) {
            for ($cursor = $index + 1; $cursor < $count; $cursor++) {
                if (wp_fts_test_native_token_is_identifier($nativeTokens[$cursor])) {
                    $nativeNames[] = $nativeTokens[$cursor][1];
                    break;
                }
                if ($nativeTokens[$cursor] === '(') {
                    break;
                }
            }
        }
        $operator = is_array($nativeTokens[$index]) ? $nativeTokens[$index][0] : null;
        if (!in_array($operator, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
            continue;
        }
        for ($cursor = $index + 1; $cursor < $count; $cursor++) {
            if (is_array($nativeTokens[$cursor]) && in_array($nativeTokens[$cursor][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (
                is_array($nativeTokens[$cursor])
                && $nativeTokens[$cursor][0] === T_STRING
                && isset($queueMutationNames[$nativeTokens[$cursor][1]])
            ) {
                $nativeCalls[] = ($operator === T_DOUBLE_COLON ? 'static:' : 'object:') . $nativeTokens[$cursor][1];
            }
            break;
        }
    }
    sort($nativeNames, SORT_STRING);
    sort($nativeCalls, SORT_STRING);

    assert_same($nativeNames, $streamNames, 'the complete fallback named-function inventory should match ext-tokenizer');
    assert_same($nativeCalls, $streamCalls, 'the complete fallback queue-mutation call inventory should match ext-tokenizer');
});

/** Whether an ext-tokenizer token is a contextually valid function name. */
function wp_fts_test_native_token_is_identifier(mixed $token): bool
{
    if (!is_array($token) || ($token[1] ?? '') === '') {
        return false;
    }
    $text = (string) $token[1];
    if (!wp_fts_php_source_is_identifier_start($text[0])) {
        return false;
    }
    for ($index = 1, $length = strlen($text); $index < $length; $index++) {
        if (!wp_fts_php_source_is_identifier_part($text[$index])) {
            return false;
        }
    }

    return true;
}
