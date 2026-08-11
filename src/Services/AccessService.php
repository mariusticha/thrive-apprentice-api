<?php

declare(strict_types=1);

namespace ThriveApi\Services;

use WP_Error;
use ThriveApi\Repositories\OrderRepository;
use ThriveApi\Repositories\ProductRepository;
use ThriveApi\Support\ExpiryParser;

class AccessService
{
    public function __construct(
        private OrderRepository $orders,
        private ProductRepository $products,
    ) {}

    // ── Context resolution ───────────────────────────────────────────────────

    /**
     * Validate user + products/orders exist, then resolve the matching order/item IDs.
     * Returns a context array or WP_Error on failure.
     */
    public function buildSemanticContext(
        int $userId,
        string $resource,
        array $recordIds,
        ?int $orderStatus = null,
        ?int $itemStatus = null,
    ): WP_Error|array {
        if (! get_user_by('id', $userId)) {
            return new WP_Error('user_not_found', "No user found for user_id {$userId}.", ['status' => 404]);
        }

        if ($resource === 'product') {
            $check = $this->products->checkProductsExist($recordIds);
            if ($check instanceof WP_Error) {
                return $check;
            }
        }

        $resolved = $this->resolveRecordsByResource($userId, $resource, $recordIds, $orderStatus, $itemStatus);

        if ($resolved instanceof WP_Error) {
            return $resolved;
        }

        if (empty($resolved['item_ids'])) {
            return new WP_Error(
                'no_orders_found',
                'No matching order records found for this request.',
                ['status' => 422]
            );
        }

        if ($resource === 'order') {
            $check = $this->products->checkProductsExist($resolved['product_ids']);
            if ($check instanceof WP_Error) {
                return $check;
            }
        }

        return $resolved;
    }

    /**
     * Route the record lookup based on whether the caller targets products or orders.
     */
    private function resolveRecordsByResource(
        int $userId,
        string $resource,
        array $recordIds,
        ?int $orderStatus,
        ?int $itemStatus,
    ): WP_Error|array {
        if ($resource === 'order') {
            $matched = $this->orders->findOrderItemsByOrderIds($userId, $recordIds, $orderStatus, $itemStatus);

            if (isset($matched['ambiguous_order_ids'])) {
                $ids = implode(', ', $matched['ambiguous_order_ids']);
                return new WP_Error(
                    'ambiguous_order',
                    "Action not possible: order(s) {$ids} contain multiple products. Use resource=product instead.",
                    ['status' => 422]
                );
            }

            return [
                'order_ids'   => $matched['order_ids'] ?? [],
                'item_ids'    => $matched['item_ids'] ?? [],
                'product_ids' => $matched['product_ids'] ?? [],
            ];
        }

        $matched = $this->orders->findOrderItemsForUpdate($userId, $recordIds, $orderStatus, $itemStatus);

        return [
            'order_ids'   => $matched['order_ids'] ?? [],
            'item_ids'    => $matched['item_ids'] ?? [],
            'product_ids' => $recordIds,
        ];
    }

    // ── Expiry resolution ────────────────────────────────────────────────────

    /**
     * Resolve the effective expires_at date for a product + user combination.
     *
     * @return array{expires_at: string|null, expiry_details: array, validation_error: string|null}
     */
    public function resolveAccessExpiry(
        int $productId,
        array $expiryConfigs,
        mixed $usermetaExpiry,
        ?int $userId = null,
    ): array {
        $expiryInfo      = ExpiryParser::parseProductExpiry($productId, $expiryConfigs);
        $expiresAt       = null;
        $validationError = null;

        if ($expiryInfo['mode'] === 'specific_time' && isset($expiryInfo['date'])) {
            $expiresAt = $expiryInfo['date'];
        } elseif ($expiryInfo['mode'] === 'after_purchase') {
            $expiresAt = $usermetaExpiry;

            if ($expiresAt === null) {
                $userContext     = $userId !== null ? " for user {$userId}" : '';
                $validationError = "ERROR: after_purchase mode requires tva_product_{$productId}_access_expiry{$userContext} but it's missing";
            }
        }

        return [
            'expires_at'       => $expiresAt,
            'expiry_details'   => $expiryInfo,
            'validation_error' => $validationError,
        ];
    }

    // ── History transformation ───────────────────────────────────────────────

    /**
     * Transform a single tva_access_history DB row into the standard API output shape.
     */
    public function transformAccessHistoryRow(
        array $row,
        array $expiryConfigs,
        mixed $usermetaExpiry,
        ?int $userId = null,
        bool $includeUserId = false,
    ): array {
        $productId = (int) $row['product_id'];
        $resolved  = $this->resolveAccessExpiry($productId, $expiryConfigs, $usermetaExpiry, $userId);
        $courseId  = is_null($row['course_id']) ? null : (int) $row['course_id'];

        $result = [
            'product_id'     => $productId,
            'course_id'      => $courseId,
            'created_at'     => $row['created'],
            'expires_at'     => $resolved['expires_at'],
            'expiry_details' => $resolved['expiry_details'],
            'source'         => $row['source'],
            'status'         => (int) $row['status'],
        ];

        if ($includeUserId && $userId !== null) {
            $result = ['user_id' => $userId] + $result;
        }

        if ($resolved['validation_error'] !== null) {
            $result['validation_error'] = $resolved['validation_error'];
        }

        return $result;
    }

    /**
     * Batch-transform multiple tva_access_history rows.
     *
     * @param array $expiryMap  product_id => date  (single-user context)
     *                          or  "{user_id}_{product_id}" => date  (multi-user context, $includeUserId=true)
     */
    public function transformAccessHistoryEvents(
        array $rows,
        array $expiryConfigs,
        array $expiryMap,
        bool $includeUserId = false,
    ): array {
        $results = [];

        foreach ($rows as $row) {
            $productId = (int) $row['product_id'];
            $userId    = isset($row['user_id']) ? (int) $row['user_id'] : null;
            $expiryKey = ($includeUserId && $userId !== null) ? $userId . '_' . $productId : $productId;

            $results[] = $this->transformAccessHistoryRow(
                $row,
                $expiryConfigs,
                $expiryMap[$expiryKey] ?? null,
                $userId,
                $includeUserId,
            );
        }

        return $results;
    }

    // ── Access evaluation ────────────────────────────────────────────────────

    /**
     * Compute the current access state for a user from their orders + expiry data.
     *
     * @param array $expiryMap  product_id => date|null
     */
    public function evaluateCurrentAccesses(
        int $userId,
        array $expiryConfigs,
        array $expiryMap,
    ): array {
        $orderItems     = $this->orders->findOrderItemsForUser($userId);
        $productIds     = array_values(array_unique(array_column($orderItems, 'product_id')));
        $productNames   = $this->products->fetchTermNames($productIds);
        $productCourses = $this->getProductCoursesMap($productIds);

        $activeAccesses   = [];
        $outdatedAccesses = [];

        foreach ($orderItems as $item) {
            $orderId        = (int) $item['order_id'];
            $orderCreatedAt = $item['order_created_at'];
            $productId      = (int) $item['product_id'];
            $isActive       = ((int) $item['order_status'] === 1 && (int) $item['item_status'] === 1);

            $productName = $productNames[$productId] ?? null;
            $courses     = $productCourses[$productId] ?? [];
            $resolved    = $this->resolveAccessExpiry($productId, $expiryConfigs, $expiryMap[$productId] ?? null, $userId);
            $expiresAt   = $resolved['expires_at'];

            if (! $isActive) {
                foreach ($courses as $course) {
                    $outdatedAccesses[] = [
                        'order_id'         => $orderId,
                        'product_id'       => $productId,
                        'product_name'     => $productName,
                        'course_id'        => $course['course_id'],
                        'course_name'      => $course['course_name'],
                        'status'           => 'revoked',
                        'order_created_at' => $orderCreatedAt,
                        'expires_at'       => $expiresAt,
                        'expiry_details'   => $resolved['expiry_details'],
                    ];
                }
                continue;
            }

            $accessStatus = 'active';

            if ($expiresAt !== null && $expiresAt < current_time('mysql')) {
                $accessStatus = 'expired';
            }

            foreach ($courses as $course) {
                $entry = [
                    'order_id'         => $orderId,
                    'product_id'       => $productId,
                    'product_name'     => $productName,
                    'course_id'        => $course['course_id'],
                    'course_name'      => $course['course_name'],
                    'status'           => $accessStatus,
                    'order_created_at' => $orderCreatedAt,
                    'expires_at'       => $expiresAt,
                    'expiry_details'   => $resolved['expiry_details'],
                ];

                if ($accessStatus === 'active') {
                    $activeAccesses[] = $entry;
                } else {
                    $outdatedAccesses[] = $entry;
                }
            }
        }

        return [
            'accesses'                => $activeAccesses,
            'outdated_accesses'       => $outdatedAccesses,
            'outdated_accesses_count' => count($outdatedAccesses),
        ];
    }

    // ── Product / course mapping ─────────────────────────────────────────────

    /**
     * For a list of product IDs, return a map of product_id => [course rows].
     * Course names are batch-fetched in a single query.
     */
    public function getProductCoursesMap(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        $rows              = $this->products->fetchProductContentSets($productIds);
        $productToCourseIds = [];
        $allCourseIds      = [];

        foreach ($rows as $row) {
            $productId                      = (int) $row['product_id'];
            $courseIds                      = ExpiryParser::extractCourseIds($row['post_content']);
            $productToCourseIds[$productId] = $courseIds;
            $allCourseIds                   = array_merge($allCourseIds, $courseIds);
        }

        $allCourseIds = array_values(array_unique($allCourseIds));
        $courseNames  = $this->products->fetchTermNames($allCourseIds);

        $productCourses = [];

        foreach ($productToCourseIds as $productId => $courseIds) {
            $courses = [];

            foreach ($courseIds as $courseId) {
                $courses[] = [
                    'course_id'   => $courseId,
                    'course_name' => $courseNames[$courseId] ?? null,
                ];
            }

            $productCourses[$productId] = $courses;
        }

        return $productCourses;
    }

    /**
     * Build the full product-course map response (used by /product-course-map).
     * Includes expiry config per product and cross-validation against access history.
     */
    public function buildProductCourseMap(): array
    {
        $rows       = $this->products->fetchAllProductContentSets();
        $productIds = array_values(array_unique(array_column($rows, 'product_id')));

        $expiryConfigs = $this->products->fetchExpiryConfigs($productIds);

        $productData     = [];
        $allCourseIds    = [];
        $definitionPairs = [];

        foreach ($rows as $row) {
            $productId = (int) $row['product_id'];
            $courseIds = ExpiryParser::extractCourseIds($row['post_content']);

            if (empty($courseIds)) {
                continue;
            }

            $productData[] = [
                'product_id'   => $productId,
                'product_name' => $row['product_name'],
                'course_ids'   => $courseIds,
            ];

            foreach ($courseIds as $courseId) {
                $allCourseIds[]                            = $courseId;
                $definitionPairs["{$productId}:{$courseId}"] = true;
            }
        }

        $allCourseIds = array_values(array_unique($allCourseIds));
        $courseNames  = $this->products->fetchTermNames($allCourseIds);

        $products = [];

        foreach ($productData as $data) {
            $courses = [];

            foreach ($data['course_ids'] as $courseId) {
                $courses[] = [
                    'course_id'   => $courseId,
                    'course_name' => $courseNames[$courseId] ?? null,
                ];
            }

            $products[] = [
                'product_id'     => $data['product_id'],
                'product_name'   => $data['product_name'],
                'courses'        => $courses,
                'expiry_details' => ExpiryParser::parseProductExpiry($data['product_id'], $expiryConfigs),
            ];
        }

        // Cross-validate definition vs history
        $historyRows  = $this->products->fetchHistoryProductCoursePairs();
        $historyPairs = [];

        foreach ($historyRows as $row) {
            $historyPairs[((int) $row['product_id']) . ':' . ((int) $row['course_id'])] = true;
        }

        $missingInDefinition = [];
        $missingInHistory    = [];

        foreach ($historyPairs as $key => $_) {
            if (! isset($definitionPairs[$key])) {
                $missingInDefinition[] = $key;
            }
        }

        foreach ($definitionPairs as $key => $_) {
            if (! isset($historyPairs[$key])) {
                $missingInHistory[] = $key;
            }
        }

        return [
            'generated_at' => current_time('mysql'),
            'products'     => $products,
            'validation'   => [
                'missing_in_definition' => array_values($missingInDefinition),
                'missing_in_history'    => array_values($missingInHistory),
            ],
        ];
    }
}
