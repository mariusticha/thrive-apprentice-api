<?php

declare(strict_types=1);

namespace ThriveApi\Support;

use WP_Error;

class ParamParser
{
    /**
     * Parse and validate a raw user_id parameter.
     */
    public static function parseUserId(mixed $raw): WP_Error|int
    {
        if (ExpiryParser::exceedsPhpIntMax($raw)) {
            return new WP_Error(
                'user_id_exceeds_max',
                'user_id exceeds the maximum supported integer value: ' . PHP_INT_MAX,
                ['status' => 400]
            );
        }

        $userId = intval($raw);

        if ($userId === 0) {
            return new WP_Error('invalid_user_id', 'user_id must be a non-zero integer', ['status' => 400]);
        }

        return $userId;
    }

    /**
     * Parse and validate a single raw record_id parameter.
     */
    public static function parseRecordId(mixed $raw): WP_Error|int
    {
        if (ExpiryParser::exceedsPhpIntMax($raw)) {
            return new WP_Error(
                'record_id_exceeds_max',
                'record_id exceeds the maximum supported integer value: ' . PHP_INT_MAX,
                ['status' => 400]
            );
        }

        $recordId = intval($raw);

        if ($recordId === 0) {
            return new WP_Error('invalid_record_id', 'record_id must be a non-zero integer', ['status' => 400]);
        }

        return $recordId;
    }

    /**
     * Parse and validate an array of raw record_id values.
     * Deduplicates and casts all values to int.
     */
    public static function parseRecordIds(mixed $raw): WP_Error|array
    {
        if (! is_array($raw) || empty($raw)) {
            return new WP_Error('invalid_record_ids', 'record_ids must be a non-empty array', ['status' => 400]);
        }

        foreach ($raw as $item) {
            if (ExpiryParser::exceedsPhpIntMax($item)) {
                return new WP_Error(
                    'record_id_exceeds_max',
                    'One or more record_ids exceed the maximum supported integer value',
                    ['status' => 400]
                );
            }
        }

        return array_values(array_unique(array_map('intval', $raw)));
    }

    /**
     * Parse and validate the since / until time range parameters.
     * Returns [$since, $until] as MySQL datetime strings, or WP_Error on failure.
     */
    public static function parseSinceAndUntil(array $params): WP_Error|array
    {
        $since = $params['since'] ?? null;

        if (empty($since)) {
            return new WP_Error('missing_since', "The 'since' parameter is required.", ['status' => 400]);
        }

        $parsedSince = strtotime($since);

        if ($parsedSince === false) {
            return new WP_Error(
                'invalid_since',
                "The 'since' parameter must be a valid date or datetime string.",
                ['status' => 400]
            );
        }

        $since = date('Y-m-d H:i:s', $parsedSince);

        if (array_key_exists('until', $params)) {
            $until = $params['until'];

            if (empty($until)) {
                return new WP_Error(
                    'invalid_until',
                    "The 'until' parameter cannot be empty when provided.",
                    ['status' => 400]
                );
            }

            $parsedUntil = strtotime($until);

            if ($parsedUntil === false) {
                return new WP_Error(
                    'invalid_until',
                    "The 'until' parameter must be a valid date or datetime string.",
                    ['status' => 400]
                );
            }

            // If no time component was given, extend to end of day.
            $dateOnly      = date('Y-m-d', $parsedUntil);
            $datetimeCheck = date('Y-m-d H:i:s', $parsedUntil);

            if ($datetimeCheck === $dateOnly . ' 00:00:00') {
                $parsedUntil = strtotime($dateOnly . ' 23:59:59');
            }

            $until = date('Y-m-d H:i:s', $parsedUntil);

            if ($parsedUntil <= $parsedSince) {
                return new WP_Error(
                    'invalid_date_range',
                    "The 'until' parameter must be later than 'since'.",
                    ['status' => 400]
                );
            }
        } else {
            $until = current_time('mysql');
        }

        return [$since, $until];
    }
}
