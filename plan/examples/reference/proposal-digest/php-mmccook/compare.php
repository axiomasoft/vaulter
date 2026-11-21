<?php

declare(strict_types=1);

/*
 * Runs mmccook/php-json-canonicalization-scheme 1.0.0 (pinned by composer.lock here) over the
 * shared vectors and asserts exactly which ones it gets wrong. JSON is decoded as stdClass, the
 * library's best case ({} and {"0":..} stay objects). Exit 0 = the documented defect set matches.
 *
 *   composer install --working-dir plan/examples/reference/proposal-digest/php-mmccook
 *   php plan/examples/reference/proposal-digest/php-mmccook/compare.php
 */

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/../php/ProposalDigest.php';

use Mmccook\JsonCanonicalizator\JsonCanonicalizatorFactory;
use Vaulter\Ai\Core\Protocol\ProposalDigest;

$vectors = json_decode((string) file_get_contents(__DIR__ . '/../vectors.json'), false, 512, JSON_THROW_ON_ERROR);
$library = JsonCanonicalizatorFactory::getInstance();
$wrong = [];

foreach ($vectors->jcs as $v) {
    if ($v->jcs === null) {
        continue; // invalid JSON for JCS; the library is not asked to classify it
    }
    try {
        $text = $library->canonicalize(json_decode($v->input_json, false, 512, JSON_THROW_ON_ERROR));
    } catch (Throwable $e) {
        $text = 'exception: ' . $e->getMessage();
    }
    if ($text !== $v->jcs) {
        $wrong[$v->id] = $text;
    }
}
foreach ($vectors->proposals as $p) {
    $record = ProposalDigest::record($p->prepare_request->input, $p->prepare_response->result);
    $text = $library->canonicalize($record);
    if ($text !== $p->canonical_record) {
        $wrong[$p->id] = 'sha256 ' . hash('sha256', $text) . ' instead of ' . $p->digest;
    }
}
foreach ($wrong as $id => $text) {
    fwrite(STDERR, "mmccook differs on $id: $text\n");
}

$documented = [
    'escapes_and_raw_line_separators',  // json_encode without JSON_UNESCAPED_LINE_TERMINATORS: \u2028 \u2029
    'integer_double_rounding',          // PHP int keeps ...993, JCS (IEEE double) gives ...992
    'integral_numbers_by_value',        // es6NumberFormat: rtrim('10.0000000', '.0') === '1'
    'large_and_small_exponents',        // 1.5e20 → '15'
    'text_astral_line_separator',       // a schema-valid replacement with U+2028 changes the digest
];
$ok = array_keys($wrong) === $documented;
fwrite(STDERR, ($ok ? 'ok   ' : 'FAIL ') . 'mmccook 1.0.0 defect set: ' . implode(', ', array_keys($wrong)) . "\n");
exit($ok ? 0 : 1);
