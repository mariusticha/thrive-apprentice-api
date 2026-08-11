<?php

declare(strict_types=1);

use ThriveApi\Support\ParamParser;
use Brain\Monkey\Functions;

// ── parseUserId ──────────────────────────────────────────────────────────────

test('parseUserId returns WP_Error for null', function (): void {
    $result = ParamParser::parseUserId(null);

    expect($result)->toBeInstanceOf(WP_Error::class);
    expect($result->get_error_code())->toBe('invalid_user_id');
});

test('parseUserId returns WP_Error for zero', function (): void {
    $result = ParamParser::parseUserId(0);

    expect($result)->toBeInstanceOf(WP_Error::class);
    expect($result->get_error_code())->toBe('invalid_user_id');
});

test('parseUserId returns WP_Error for a string that resolves to zero', function (): void {
    $result = ParamParser::parseUserId('abc');

    expect($result)->toBeInstanceOf(WP_Error::class);
    expect($result->get_error_code())->toBe('invalid_user_id');
});

test('parseUserId returns the int for a valid positive integer', function (): void {
    expect(ParamParser::parseUserId(42))->toBe(42);
    expect(ParamParser::parseUserId('99'))->toBe(99);
});

test('parseUserId returns WP_Error when value exceeds PHP_INT_MAX', function (): void {
    $result = ParamParser::parseUserId('99999999999999999999999');

    expect($result)->toBeInstanceOf(WP_Error::class);
    expect($result->get_error_code())->toBe('user_id_exceeds_max');
});

// ── parseRecordId ────────────────────────────────────────────────────────────

test('parseRecordId returns WP_Error for zero', function (): void {
    $result = ParamParser::parseRecordId(0);

    expect($result)->toBeInstanceOf(WP_Error::class);
    expect($result->get_error_code())->toBe('invalid_record_id');
});

test('parseRecordId returns the int for a valid positive integer', function (): void {
    expect(ParamParser::parseRecordId(5))->toBe(5);
    expect(ParamParser::parseRecordId('12'))->toBe(12);
});

test('parseRecordId returns WP_Error when value exceeds PHP_INT_MAX', function (): void {
    $result = ParamParser::parseRecordId('99999999999999999999999');

    expect($result)->toBeInstanceOf(WP_Error::class);
    expect($result->get_error_code())->toBe('record_id_exceeds_max');
});

// ── parseRecordIds ───────────────────────────────────────────────────────────

test('parseRecordIds returns WP_Error for a non-array value', function (): void {
    $result = ParamParser::parseRecordIds('not_an_array');

    expect($result)->toBeInstanceOf(WP_Error::class);
    expect($result->get_error_code())->toBe('invalid_record_ids');
});

test('parseRecordIds returns WP_Error for an empty array', function (): void {
    $result = ParamParser::parseRecordIds([]);

    expect($result)->toBeInstanceOf(WP_Error::class);
    expect($result->get_error_code())->toBe('invalid_record_ids');
});

test('parseRecordIds returns WP_Error when any value exceeds PHP_INT_MAX', function (): void {
    $result = ParamParser::parseRecordIds([1, 2, '99999999999999999999999']);

    expect($result)->toBeInstanceOf(WP_Error::class);
    expect($result->get_error_code())->toBe('record_id_exceeds_max');
});

test('parseRecordIds deduplicates and casts all values to int', function (): void {
    $result = ParamParser::parseRecordIds(['3', '1', '2', '2', '3']);

    expect($result)->toBe([3, 1, 2]);
});

// ── parseSinceAndUntil ───────────────────────────────────────────────────────

test('parseSinceAndUntil returns WP_Error when since is missing', function (): void {
    $result = ParamParser::parseSinceAndUntil([]);

    expect($result)->toBeInstanceOf(WP_Error::class);
    expect($result->get_error_code())->toBe('missing_since');
});

test('parseSinceAndUntil returns WP_Error for an invalid since string', function (): void {
    $result = ParamParser::parseSinceAndUntil(['since' => 'not_a_date']);

    expect($result)->toBeInstanceOf(WP_Error::class);
    expect($result->get_error_code())->toBe('invalid_since');
});

test('parseSinceAndUntil returns WP_Error when until is an empty string', function (): void {
    $result = ParamParser::parseSinceAndUntil(['since' => '2026-01-01', 'until' => '']);

    expect($result)->toBeInstanceOf(WP_Error::class);
    expect($result->get_error_code())->toBe('invalid_until');
});

test('parseSinceAndUntil returns WP_Error for an invalid until string', function (): void {
    $result = ParamParser::parseSinceAndUntil(['since' => '2026-01-01', 'until' => 'not_a_date']);

    expect($result)->toBeInstanceOf(WP_Error::class);
    expect($result->get_error_code())->toBe('invalid_until');
});

test('parseSinceAndUntil returns WP_Error when until is not later than since', function (): void {
    $result = ParamParser::parseSinceAndUntil(['since' => '2026-06-10', 'until' => '2026-06-01']);

    expect($result)->toBeInstanceOf(WP_Error::class);
    expect($result->get_error_code())->toBe('invalid_date_range');
});

test('parseSinceAndUntil returns WP_Error when until equals since', function (): void {
    $result = ParamParser::parseSinceAndUntil(['since' => '2026-06-10 12:00:00', 'until' => '2026-06-10 12:00:00']);

    expect($result)->toBeInstanceOf(WP_Error::class);
    expect($result->get_error_code())->toBe('invalid_date_range');
});

test('parseSinceAndUntil normalises since to a MySQL datetime string', function (): void {
    Functions\stubs(['current_time' => fn() => '2026-06-24 12:00:00']);

    $result = ParamParser::parseSinceAndUntil(['since' => '2026-01-15']);

    expect($result)->toBeArray();
    expect($result[0])->toBe('2026-01-15 00:00:00');
});

test('parseSinceAndUntil extends a date-only until to end of day', function (): void {
    $result = ParamParser::parseSinceAndUntil(['since' => '2026-01-01', 'until' => '2026-01-31']);

    expect($result)->toBeArray();
    expect($result[1])->toBe('2026-01-31 23:59:59');
});

test('parseSinceAndUntil preserves a full datetime until value unchanged', function (): void {
    $result = ParamParser::parseSinceAndUntil(['since' => '2026-01-01', 'until' => '2026-01-31 15:30:00']);

    expect($result)->toBeArray();
    expect($result[1])->toBe('2026-01-31 15:30:00');
});

test('parseSinceAndUntil defaults until to current_time when omitted', function (): void {
    Functions\expect('current_time')->once()->with('mysql')->andReturn('2026-06-24 12:00:00');

    $result = ParamParser::parseSinceAndUntil(['since' => '2026-06-01']);

    expect($result)->toBeArray();
    expect($result[1])->toBe('2026-06-24 12:00:00');
});
