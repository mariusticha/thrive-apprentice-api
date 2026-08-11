<?php

declare(strict_types=1);

use ThriveApi\Support\ExpiryParser;
use Brain\Monkey\Functions;

// Helper: stub maybe_unserialize using PHP's native unserialize.
// The regex guards against calling unserialize on non-serialised strings, which
// avoids PHP notices that Pest would flag as a risked test.
beforeEach(function (): void {
    Functions\stubs([
        'maybe_unserialize' => function (mixed $v): mixed {
            if (is_string($v) && preg_match('/^[aObidNsCr]:[0-9]/i', $v)) {
                $unserialized = unserialize($v);
                return $unserialized !== false ? $unserialized : $v;
            }
            return $v;
        },
    ]);
});

// ── parseProductExpiry ───────────────────────────────────────────────────────

test('parseProductExpiry returns not_configured when product ID is absent', function (): void {
    $result = ExpiryParser::parseProductExpiry(1, []);

    expect($result['mode'])->toBe('not_configured');
    expect($result['message'])->toContain('1');
});

test('parseProductExpiry returns not_configured when meta_value is not a valid array', function (): void {
    $result = ExpiryParser::parseProductExpiry(1, [1 => serialize('not_an_array')]);

    expect($result['mode'])->toBe('not_configured');
});

test('parseProductExpiry returns not_configured when expiry key is missing', function (): void {
    $result = ExpiryParser::parseProductExpiry(1, [1 => serialize(['enabled' => 1])]);

    expect($result['mode'])->toBe('not_configured');
});

test('parseProductExpiry returns unlimited when enabled is 0', function (): void {
    $data   = serialize(['enabled' => 0, 'expiry' => ['cond' => 'specific_time', 'cond_datetime' => '2026-01-01 00:00:00']]);
    $result = ExpiryParser::parseProductExpiry(1, [1 => $data]);

    expect($result['mode'])->toBe('unlimited');
    expect($result['date'])->toBeNull();
    expect($result['duration'])->toBeNull();
});

test('parseProductExpiry returns specific_time with full datetime', function (): void {
    $data   = serialize(['enabled' => 1, 'expiry' => ['cond' => 'specific_time', 'cond_datetime' => '2026-06-30 23:59:59']]);
    $result = ExpiryParser::parseProductExpiry(1, [1 => $data]);

    expect($result['mode'])->toBe('specific_time');
    expect($result['date'])->toBe('2026-06-30 23:59:59');
    expect($result['duration'])->toBeNull();
});

test('parseProductExpiry appends :59 when cond_datetime has no seconds', function (): void {
    $data   = serialize(['enabled' => 1, 'expiry' => ['cond' => 'specific_time', 'cond_datetime' => '2026-06-30 23:59']]);
    $result = ExpiryParser::parseProductExpiry(1, [1 => $data]);

    expect($result['date'])->toBe('2026-06-30 23:59:59');
});

test('parseProductExpiry returns after_purchase with duration structure', function (): void {
    $data = serialize([
        'enabled' => 1,
        'expiry'  => ['cond' => 'after_purchase', 'cond_purchase' => ['number' => 30, 'unit' => 'days']],
    ]);
    $result = ExpiryParser::parseProductExpiry(1, [1 => $data]);

    expect($result['mode'])->toBe('after_purchase');
    expect($result['date'])->toBeNull();
    expect($result['duration']['number'])->toBe(30);
    expect($result['duration']['unit'])->toBe('days');
});

test('parseProductExpiry returns other mode for unknown cond', function (): void {
    $data   = serialize(['enabled' => 1, 'expiry' => ['cond' => 'something_weird']]);
    $result = ExpiryParser::parseProductExpiry(1, [1 => $data]);

    expect($result['mode'])->toBe('other');
    expect($result['message'])->toContain('something_weird');
});

// ── extractCourseIds ─────────────────────────────────────────────────────────

test('extractCourseIds returns empty array for non-serialised string', function (): void {
    $result = ExpiryParser::extractCourseIds('plain_text');

    expect($result)->toBe([]);
});

test('extractCourseIds returns empty array for serialised non-array', function (): void {
    $result = ExpiryParser::extractCourseIds(serialize('still_a_string'));

    expect($result)->toBe([]);
});

test('extractCourseIds extracts course IDs from a single tva_courses rule', function (): void {
    $data   = serialize([['content_type' => 'term', 'content' => 'tva_courses', 'value' => [10, 20, 30]]]);
    $result = ExpiryParser::extractCourseIds($data);

    expect($result)->toBe([10, 20, 30]);
});

test('extractCourseIds ignores rules with a non-term content_type', function (): void {
    $data = serialize([
        ['content_type' => 'post', 'content' => 'tva_courses', 'value' => [1, 2]],
        ['content_type' => 'term', 'content' => 'tva_courses', 'value' => [99]],
    ]);
    $result = ExpiryParser::extractCourseIds($data);

    expect($result)->toBe([99]);
});

test('extractCourseIds ignores rules for taxonomies other than tva_courses', function (): void {
    $data = serialize([
        ['content_type' => 'term', 'content' => 'other_tax', 'value' => [5, 6]],
        ['content_type' => 'term', 'content' => 'tva_courses', 'value' => [7]],
    ]);
    $result = ExpiryParser::extractCourseIds($data);

    expect($result)->toBe([7]);
});

test('extractCourseIds deduplicates IDs across multiple rules', function (): void {
    $data = serialize([
        ['content_type' => 'term', 'content' => 'tva_courses', 'value' => [1, 2]],
        ['content_type' => 'term', 'content' => 'tva_courses', 'value' => [2, 3]],
    ]);
    $result = ExpiryParser::extractCourseIds($data);

    expect($result)->toBe([1, 2, 3]);
});

// ── exceedsPhpIntMax ─────────────────────────────────────────────────────────

test('exceedsPhpIntMax returns false for a normal positive integer', function (): void {
    expect(ExpiryParser::exceedsPhpIntMax(42))->toBeFalse();
});

test('exceedsPhpIntMax returns false for a normal integer string', function (): void {
    expect(ExpiryParser::exceedsPhpIntMax('100'))->toBeFalse();
});

test('exceedsPhpIntMax returns false for PHP_INT_MAX itself', function (): void {
    expect(ExpiryParser::exceedsPhpIntMax((string) PHP_INT_MAX))->toBeFalse();
});

test('exceedsPhpIntMax returns true for a float exceeding PHP_INT_MAX', function (): void {
    expect(ExpiryParser::exceedsPhpIntMax((float) PHP_INT_MAX * 2))->toBeTrue();
});

test('exceedsPhpIntMax returns true for a digit string exceeding PHP_INT_MAX', function (): void {
    expect(ExpiryParser::exceedsPhpIntMax('99999999999999999999999'))->toBeTrue();
});

test('exceedsPhpIntMax returns false for non-numeric values', function (): void {
    expect(ExpiryParser::exceedsPhpIntMax('abc'))->toBeFalse();
    expect(ExpiryParser::exceedsPhpIntMax(null))->toBeFalse();
    expect(ExpiryParser::exceedsPhpIntMax([]))->toBeFalse();
});
