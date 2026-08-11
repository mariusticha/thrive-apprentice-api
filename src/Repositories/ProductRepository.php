<?php

declare(strict_types=1);

namespace ThriveApi\Repositories;

use WP_Error;

class ProductRepository
{
    public function __construct(private \wpdb $db) {}

    // ── Read queries ─────────────────────────────────────────────────────────

    /**
     * Batch-fetch access_expiry termmeta for the given product IDs.
     * Returns [ product_id => raw_meta_value (serialised string) ]
     */
    public function fetchExpiryConfigs(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        $ph   = implode(',', array_fill(0, count($productIds), '%d'));
        $rows = $this->db->get_results(
            $this->db->prepare(
                "SELECT term_id, meta_value FROM {$this->db->termmeta}
                WHERE term_id IN ($ph) AND meta_key = 'access_expiry'",
                ...$productIds
            ),
            ARRAY_A
        );

        $configs = [];

        foreach ($rows as $row) {
            $configs[(int) $row['term_id']] = $row['meta_value'];
        }

        return $configs;
    }

    /**
     * Batch-fetch per-user expiry dates from usermeta for multiple users.
     * Returns a flat map keyed by "{user_id}_{product_id}" => date|null.
     */
    public function fetchUserExpiryDates(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $ph   = implode(',', array_fill(0, count($userIds), '%d'));
        $rows = $this->db->get_results(
            $this->db->prepare(
                "SELECT user_id, meta_key, meta_value FROM {$this->db->usermeta}
                WHERE user_id IN ($ph) AND meta_key LIKE 'tva_product%_access_expiry'",
                ...$userIds
            ),
            ARRAY_A
        );

        $map = [];

        foreach ($rows as $row) {
            if (preg_match('/tva_product_(\d+)_access_expiry/', $row['meta_key'], $m)) {
                $key       = ((int) $row['user_id']) . '_' . ((int) $m[1]);
                $map[$key] = $row['meta_value'] !== '' ? $row['meta_value'] : null;
            }
        }

        return $map;
    }

    /**
     * Fetch per-user expiry dates for a single user.
     * Returns a flat map keyed by product_id => date|null.
     */
    public function fetchUserExpiryDatesForUser(int $userId): array
    {
        $rows = $this->db->get_results(
            $this->db->prepare(
                "SELECT meta_key, meta_value FROM {$this->db->usermeta}
                WHERE user_id = %d AND meta_key LIKE 'tva_product\_%\_access_expiry'",
                $userId
            ),
            ARRAY_A
        );

        $map = [];

        foreach ($rows as $row) {
            if (preg_match('/tva_product_(\d+)_access_expiry/', $row['meta_key'], $m)) {
                $map[(int) $m[1]] = $row['meta_value'] !== '' ? $row['meta_value'] : null;
            }
        }

        return $map;
    }

    /**
     * Batch-fetch term names by ID (works for both products and courses, both are wp_terms).
     * Returns [ term_id => name ]
     */
    public function fetchTermNames(array $termIds): array
    {
        if (empty($termIds)) {
            return [];
        }

        $ph   = implode(',', array_fill(0, count($termIds), '%d'));
        $rows = $this->db->get_results(
            $this->db->prepare(
                "SELECT term_id, name FROM {$this->db->terms} WHERE term_id IN ($ph)",
                ...$termIds
            ),
            ARRAY_A
        );

        $names = [];

        foreach ($rows as $row) {
            $names[(int) $row['term_id']] = $row['name'];
        }

        return $names;
    }

    /**
     * Fetch content set rows (post_content + product term) for the given product IDs.
     * Used to extract course ID lists from Thrive's tvd_content_set posts.
     */
    public function fetchProductContentSets(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        $ph = implode(',', array_fill(0, count($productIds), '%d'));

        return $this->db->get_results(
            $this->db->prepare(
                "SELECT p.post_content, t.term_id AS product_id
                FROM {$this->db->posts} p
                JOIN {$this->db->term_relationships} tr ON tr.object_id = p.ID
                JOIN {$this->db->terms} t ON t.term_id = tr.term_taxonomy_id
                WHERE p.post_type = 'tvd_content_set' AND t.term_id IN ($ph)",
                ...$productIds
            ),
            ARRAY_A
        );
    }

    /**
     * Fetch all content sets (used by the full /product-course-map endpoint).
     */
    public function fetchAllProductContentSets(): array
    {
        return $this->db->get_results(
            "SELECT p.ID AS post_id, p.post_content, t.term_id AS product_id, t.name AS product_name
            FROM {$this->db->posts} p
            JOIN {$this->db->term_relationships} tr ON tr.object_id = p.ID
            JOIN {$this->db->terms} t ON t.term_id = tr.term_taxonomy_id
            WHERE p.post_type = 'tvd_content_set'",
            ARRAY_A
        );
    }

    /**
     * Fetch all distinct product+course pairs recorded in access history.
     */
    public function fetchHistoryProductCoursePairs(): array
    {
        return $this->db->get_results(
            "SELECT DISTINCT product_id, course_id FROM {$this->db->prefix}tva_access_history
            WHERE product_id IS NOT NULL AND course_id IS NOT NULL",
            ARRAY_A
        );
    }

    /**
     * Check that all given product IDs exist in wp_terms.
     * Returns WP_Error with missing IDs if any are not found, null otherwise.
     */
    public function checkProductsExist(array $productIds): WP_Error|null
    {
        if (empty($productIds)) {
            return null;
        }

        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        $ph         = implode(',', array_fill(0, count($productIds), '%d'));
        $existing   = $this->db->get_col(
            $this->db->prepare(
                "SELECT term_id FROM {$this->db->terms} WHERE term_id IN ($ph)",
                ...$productIds
            )
        );

        $existing = array_map('intval', $existing);
        $missing  = array_values(array_diff($productIds, $existing));

        if (! empty($missing)) {
            return new WP_Error(
                'product_not_found',
                'No product found for product_id(s): ' . implode(', ', $missing),
                ['status' => 404]
            );
        }

        return null;
    }

    // ── Mutation queries ─────────────────────────────────────────────────────

    /**
     * Delete per-user expiry usermeta entries for the given product IDs.
     * Returns affected row count.
     */
    public function deleteUserExpiryMeta(int $userId, array $productIds): int
    {
        if (empty($productIds)) {
            return 0;
        }

        $metaKeys = array_map(fn(int $id) => "tva_product_{$id}_access_expiry", $productIds);
        $ph       = implode(',', array_fill(0, count($metaKeys), '%s'));

        $this->db->query(
            $this->db->prepare(
                "DELETE FROM {$this->db->usermeta} WHERE user_id = %d AND meta_key IN ($ph)",
                $userId,
                ...$metaKeys
            )
        );

        return $this->db->rows_affected;
    }

    /**
     * Insert or update the per-user per-product expiry date in usermeta.
     * Returns 'updated', 'created', or 'unchanged'.
     */
    public function upsertUserExpiryDate(int $userId, int $productId, string $expiresAt): string
    {
        $metaKey  = "tva_product_{$productId}_access_expiry";
        $existing = $this->db->get_var(
            $this->db->prepare(
                "SELECT meta_value FROM {$this->db->usermeta} WHERE user_id = %d AND meta_key = %s LIMIT 1",
                $userId,
                $metaKey
            )
        );

        if ($existing !== null) {
            if ((string) $existing === $expiresAt) {
                return 'unchanged';
            }

            $this->db->query(
                $this->db->prepare(
                    "UPDATE {$this->db->usermeta} SET meta_value = %s WHERE user_id = %d AND meta_key = %s",
                    $expiresAt,
                    $userId,
                    $metaKey
                )
            );

            return 'updated';
        }

        $this->db->insert(
            $this->db->usermeta,
            ['user_id' => $userId, 'meta_key' => $metaKey, 'meta_value' => $expiresAt],
            ['%d', '%s', '%s']
        );

        return 'created';
    }
}
