<?php

declare(strict_types=1);

namespace ThriveApi\Support;

class ExpiryParser
{
    /**
     * Parse the serialised access_expiry termmeta value for a product into a structured array.
     *
     * @param int   $productId     The product term_id.
     * @param array $expiryConfigs Map of product_id => raw meta_value (serialised string or decoded array).
     */
    public static function parseProductExpiry(int $productId, array $expiryConfigs): array
    {
        if (! isset($expiryConfigs[$productId])) {
            return [
                'mode'    => 'not_configured',
                'message' => 'access_expiry not given for product ' . $productId,
            ];
        }

        $expiryData = maybe_unserialize($expiryConfigs[$productId]);

        if (! is_array($expiryData) || ! isset($expiryData['expiry'])) {
            return [
                'mode'    => 'not_configured',
                'message' => 'expiry not parsable',
            ];
        }

        $enabled = isset($expiryData['enabled']) ? (int) $expiryData['enabled'] : 0;

        if ($enabled === 0) {
            return ['mode' => 'unlimited', 'date' => null, 'duration' => null];
        }

        $expiry = $expiryData['expiry'];
        $cond   = $expiry['cond'] ?? null;

        if ($cond === 'specific_time' && ! empty($expiry['cond_datetime'])) {
            $date = $expiry['cond_datetime'];
            // Normalise HH:MM to HH:MM:59 to ensure the full minute is included.
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $date)) {
                $date .= ':59';
            }
            return ['mode' => 'specific_time', 'date' => $date, 'duration' => null];
        }

        if ($cond === 'after_purchase' && isset($expiry['cond_purchase'])) {
            $duration = $expiry['cond_purchase'];
            return [
                'mode'     => 'after_purchase',
                'date'     => null,
                'duration' => [
                    'number' => (int) ($duration['number'] ?? 0),
                    'unit'   => $duration['unit'] ?? '',
                ],
            ];
        }

        return ['mode' => 'other', 'message' => 'other expiry: ' . ($cond ?? 'unknown')];
    }

    /**
     * Extract all course term_ids from a tvd_content_set post_content value.
     */
    public static function extractCourseIds(string $postContent): array
    {
        $data = maybe_unserialize($postContent);

        if (! is_array($data)) {
            return [];
        }

        $courseIds = [];

        foreach ($data as $rule) {
            if (
                isset($rule['content_type'], $rule['content'], $rule['value'])
                && $rule['content_type'] === 'term'
                && $rule['content'] === 'tva_courses'
                && is_array($rule['value'])
            ) {
                foreach ($rule['value'] as $id) {
                    $courseIds[] = (int) $id;
                }
            }
        }

        return array_values(array_unique($courseIds));
    }

    /**
     * Return true when $value represents an integer larger than PHP_INT_MAX.
     */
    public static function exceedsPhpIntMax(mixed $value): bool
    {
        if (is_float($value) && $value > PHP_INT_MAX) {
            return true;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if (preg_match('/^\d+$/', $trimmed) === 1) {
                $normalized = ltrim($trimmed, '0');
                $normalized = $normalized === '' ? '0' : $normalized;
                $maxInt     = (string) PHP_INT_MAX;

                if (
                    strlen($normalized) > strlen($maxInt)
                    || (strlen($normalized) === strlen($maxInt) && strcmp($normalized, $maxInt) > 0)
                ) {
                    return true;
                }
            }
        }

        return false;
    }
}
