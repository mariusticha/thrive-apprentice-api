<?php

declare(strict_types=1);

namespace ThriveApi\Repositories;

class OrderRepository
{
    public function __construct(private \wpdb $db) {}

    // ── Read queries ─────────────────────────────────────────────────────────

    /**
     * Find order + item IDs for a user filtered by product IDs,
     * optionally constrained by order/item status.
     */
    public function findOrderItemsForUpdate(
        int $userId,
        array $productIds,
        ?int $orderStatus = null,
        ?int $itemStatus = null,
    ): array {
        if ($orderStatus !== null) {
            $orderSql  = "SELECT ID FROM {$this->db->prefix}tva_orders WHERE user_id = %d AND status = %d";
            $orderArgs = [$userId, $orderStatus];
        } else {
            $orderSql  = "SELECT ID FROM {$this->db->prefix}tva_orders WHERE user_id = %d";
            $orderArgs = [$userId];
        }

        $allOrderIds = $this->db->get_col($this->db->prepare($orderSql, ...$orderArgs));

        if (empty($allOrderIds)) {
            return ['order_ids' => [], 'item_ids' => []];
        }

        $orderPh   = implode(',', array_fill(0, count($allOrderIds), '%d'));
        $productPh = implode(',', array_fill(0, count($productIds), '%d'));

        if ($itemStatus !== null) {
            $itemsSql  = "SELECT id AS item_id, order_id
                FROM {$this->db->prefix}tva_order_items
                WHERE order_id IN ($orderPh) AND product_id IN ($productPh) AND status = %d";
            $itemsArgs = [...$allOrderIds, ...$productIds, $itemStatus];
        } else {
            $itemsSql  = "SELECT id AS item_id, order_id
                FROM {$this->db->prefix}tva_order_items
                WHERE order_id IN ($orderPh) AND product_id IN ($productPh)";
            $itemsArgs = [...$allOrderIds, ...$productIds];
        }

        $matchedItems = $this->db->get_results($this->db->prepare($itemsSql, ...$itemsArgs), ARRAY_A);

        if (empty($matchedItems)) {
            return ['order_ids' => [], 'item_ids' => []];
        }

        return [
            'order_ids' => array_values(array_unique(array_column($matchedItems, 'order_id'))),
            'item_ids'  => array_column($matchedItems, 'item_id'),
        ];
    }

    /**
     * Find order + item IDs by explicit order IDs (order-mode mutations).
     * Returns ['ambiguous_order_ids' => [...]] when any matched order contains multiple items.
     */
    public function findOrderItemsByOrderIds(
        int $userId,
        array $orderIds,
        ?int $orderStatus = null,
        ?int $itemStatus = null,
    ): array {
        $orderPh = implode(',', array_fill(0, count($orderIds), '%d'));

        if ($orderStatus !== null) {
            $orderSql  = "SELECT ID FROM {$this->db->prefix}tva_orders WHERE ID IN ($orderPh) AND user_id = %d AND status = %d";
            $orderArgs = [...$orderIds, $userId, $orderStatus];
        } else {
            $orderSql  = "SELECT ID FROM {$this->db->prefix}tva_orders WHERE ID IN ($orderPh) AND user_id = %d";
            $orderArgs = [...$orderIds, $userId];
        }

        $matchedOrderIds = $this->db->get_col($this->db->prepare($orderSql, ...$orderArgs));

        if (empty($matchedOrderIds)) {
            return ['order_ids' => [], 'item_ids' => [], 'product_ids' => []];
        }

        $matchedPh = implode(',', array_fill(0, count($matchedOrderIds), '%d'));

        if ($itemStatus !== null) {
            $itemsSql  = "SELECT id AS item_id, order_id, product_id
                FROM {$this->db->prefix}tva_order_items
                WHERE order_id IN ($matchedPh) AND status = %d";
            $itemsArgs = [...$matchedOrderIds, $itemStatus];
        } else {
            $itemsSql  = "SELECT id AS item_id, order_id, product_id
                FROM {$this->db->prefix}tva_order_items
                WHERE order_id IN ($matchedPh)";
            $itemsArgs = $matchedOrderIds;
        }

        $matchedItems = $this->db->get_results($this->db->prepare($itemsSql, ...$itemsArgs), ARRAY_A);

        if (empty($matchedItems)) {
            return ['order_ids' => [], 'item_ids' => [], 'product_ids' => []];
        }

        $itemsPerOrder = array_count_values(array_column($matchedItems, 'order_id'));
        $ambiguous     = array_keys(array_filter($itemsPerOrder, fn(int $count) => $count > 1));

        if (! empty($ambiguous)) {
            return ['ambiguous_order_ids' => $ambiguous];
        }

        return [
            'order_ids'   => array_values(array_unique(array_column($matchedItems, 'order_id'))),
            'item_ids'    => array_column($matchedItems, 'item_id'),
            'product_ids' => array_values(array_unique(array_map('intval', array_column($matchedItems, 'product_id')))),
        ];
    }

    /**
     * Fetch all order + item records for a user (used to evaluate current access state).
     */
    public function findOrderItemsForUser(int $userId): array
    {
        return $this->db->get_results(
            $this->db->prepare(
                "SELECT o.ID AS order_id, o.created_at AS order_created_at,
                    oi.product_id, o.status AS order_status, oi.status AS item_status
                FROM {$this->db->prefix}tva_orders o
                JOIN {$this->db->prefix}tva_order_items oi ON oi.order_id = o.ID
                WHERE o.user_id = %d",
                $userId
            ),
            ARRAY_A
        );
    }

    /**
     * Fetch distinct product IDs from access history for the given users.
     */
    public function fetchDistinctProductIdsForUsers(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $ph = implode(',', array_fill(0, count($userIds), '%d'));

        return $this->db->get_col(
            $this->db->prepare(
                "SELECT DISTINCT product_id FROM {$this->db->prefix}tva_access_history WHERE user_id IN ($ph)",
                ...$userIds
            )
        );
    }

    /**
     * Fetch the full access history for a single user, ordered chronologically.
     */
    public function fetchAccessHistoryForUser(int $userId): array
    {
        return $this->db->get_results(
            $this->db->prepare(
                "SELECT product_id, course_id, source, status, created
                FROM {$this->db->prefix}tva_access_history
                WHERE user_id = %d ORDER BY created ASC",
                $userId
            ),
            ARRAY_A
        );
    }

    /**
     * Fetch new (active) orders in a time range, joined with their items.
     */
    public function fetchOrdersInTimeRange(string $since, string $until): array
    {
        return $this->db->get_results(
            $this->db->prepare(
                "SELECT o.ID AS order_id, o.user_id, o.created_at AS order_created_at,
                    oi.product_id, o.status AS order_status, oi.status AS item_status
                FROM {$this->db->prefix}tva_orders o
                JOIN {$this->db->prefix}tva_order_items oi ON oi.order_id = o.ID
                WHERE o.created_at >= %s AND o.created_at <= %s AND o.status = 1 AND oi.status = 1
                ORDER BY o.created_at ASC",
                $since,
                $until
            ),
            ARRAY_A
        );
    }

    /**
     * Fetch all revoked orders (status = 4) that belong to a real WP user.
     */
    public function fetchRevokedOrders(): array
    {
        return $this->db->get_results(
            "SELECT o.ID AS order_id, o.status, o.created_at
            FROM {$this->db->prefix}tva_orders o
            WHERE o.status = 4
              AND o.user_id IN (SELECT ID FROM {$this->db->prefix}users)
            ORDER BY o.created_at ASC",
            ARRAY_A
        );
    }

    // ── Mutation queries ─────────────────────────────────────────────────────

    /**
     * Batch-update the status column on tva_orders. Returns affected row count.
     */
    public function updateOrderStatuses(array $orderIds, int $status): int
    {
        if (empty($orderIds)) {
            return 0;
        }

        $ph = implode(',', array_fill(0, count($orderIds), '%d'));
        $this->db->query(
            $this->db->prepare(
                "UPDATE {$this->db->prefix}tva_orders SET status = %d WHERE ID IN ($ph)",
                $status,
                ...$orderIds
            )
        );

        return $this->db->rows_affected;
    }

    /**
     * Batch-update the status column on tva_order_items. Returns affected row count.
     */
    public function updateItemStatuses(array $itemIds, int $status): int
    {
        if (empty($itemIds)) {
            return 0;
        }

        $ph = implode(',', array_fill(0, count($itemIds), '%d'));
        $this->db->query(
            $this->db->prepare(
                "UPDATE {$this->db->prefix}tva_order_items SET status = %d WHERE id IN ($ph)",
                $status,
                ...$itemIds
            )
        );

        return $this->db->rows_affected;
    }

    /**
     * Permanently delete order items by ID. Returns affected row count.
     */
    public function deleteOrderItems(array $itemIds): int
    {
        if (empty($itemIds)) {
            return 0;
        }

        $ph = implode(',', array_fill(0, count($itemIds), '%d'));
        $this->db->query(
            $this->db->prepare(
                "DELETE FROM {$this->db->prefix}tva_order_items WHERE id IN ($ph)",
                ...$itemIds
            )
        );

        return $this->db->rows_affected;
    }

    /**
     * Permanently delete orders by ID. Returns affected row count.
     */
    public function deleteOrders(array $orderIds): int
    {
        if (empty($orderIds)) {
            return 0;
        }

        $ph = implode(',', array_fill(0, count($orderIds), '%d'));
        $this->db->query(
            $this->db->prepare(
                "DELETE FROM {$this->db->prefix}tva_orders WHERE ID IN ($ph)",
                ...$orderIds
            )
        );

        return $this->db->rows_affected;
    }

    /**
     * Delete access history entries for a user + set of product IDs. Returns affected row count.
     */
    public function deleteAccessHistoryForUser(int $userId, array $productIds): int
    {
        if (empty($productIds)) {
            return 0;
        }

        $ph = implode(',', array_fill(0, count($productIds), '%d'));
        $this->db->query(
            $this->db->prepare(
                "DELETE FROM {$this->db->prefix}tva_access_history WHERE user_id = %d AND product_id IN ($ph)",
                $userId,
                ...$productIds
            )
        );

        return $this->db->rows_affected;
    }
}
