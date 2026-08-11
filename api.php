<?php

declare(strict_types=1);

/**
 * Plugin Name: Thrive Apprentice API
 * Description: Exposes Thrive Apprentice access data via REST API. Supports reading access history and
 *              current access state per user (/accesses), querying accesses by time range (/accesses/since),
 *              retrieving the product-to-course map (/product-course-map), and writing access changes:
 *              revoke (/accesses/revoke), restore (/accesses/restore), update expiry (/accesses/update),
 *              permanently delete access records (/accesses/delete), and delete a WP user with all
 *              Thrive Apprentice data (/users/delete).
 * Version: 2.0.0
 */

if (! defined('ABSPATH')) {
    exit;
}

/* - - -  E N D P O I N T S - - - */

/**
 *  /accesses
 *  /accesses/since
 */
add_action('rest_api_init', function (): void {

    register_rest_route(
        'apprentice/v1',
        '/accesses',
        [
            'methods'             => 'POST',
            'callback'            => 'get_accesses_by_user_ids',
            'permission_callback' => function () {
                return current_user_can('list_users');
            },
            'args' => [
                'user_ids' => [
                    'required' => false,
                    'type'     => 'array',
                ],
            ],
        ]
    );

    register_rest_route(
        'apprentice/v1',
        '/accesses/since',
        [
            'methods'             => 'POST',
            'callback'            => 'get_accesses_by_time',
            'permission_callback' => function (): bool {
                return current_user_can('list_users');
            },
            'args' => [
                'since' => [
                    'required' => false,
                    'type'     => 'string',
                ],
                'until' => [
                    'required' => false,
                    'type'     => 'string',
                ],
                'include_revocations' => [
                    'required' => false,        // defaults: true
                    'type'     => 'boolean',
                ],
            ],
        ]
    );
});

/**
 *  /product-course-map
 */
add_action('rest_api_init', function (): void {

    register_rest_route(
        'apprentice/v1',
        '/product-course-map',
        [
            'methods'             => 'GET',
            'callback'            => 'apprentice_product_course_map',
            'permission_callback' => function (): bool {
                return current_user_can('list_users');
            },
        ]
    );
});

/**
 *  /accesses/restore
 */
add_action('rest_api_init', function (): void {
    register_rest_route(
        'apprentice/v1',
        '/accesses/restore',
        [
            'methods'             => 'PATCH',
            'callback'            => 'restore_user_accesses',
            'permission_callback' => function (): bool {
                return current_user_can('list_users');
            },
            'args' => [
                'user_id' => [
                    'required' => true,
                    'type'     => 'integer',
                ],
                'record_ids' => [
                    'required' => true,
                    'type'     => 'array',
                ],
                'resource' => [
                    'required'          => true,
                    'type'              => 'string',
                    'validate_callback' => fn($v) => in_array($v, ['product', 'order'], true),
                ],
            ],
        ]
    );
});

/**
 *  /accesses/revoke
 */
add_action('rest_api_init', function (): void {

    register_rest_route(
        'apprentice/v1',
        '/accesses/revoke',
        [
            'methods'             => 'PATCH',
            'callback'            => 'revoke_user_accesses',
            'permission_callback' => function (): bool {
                return current_user_can('list_users');
            },
            'args' => [
                'user_id' => [
                    'required' => true,
                    'type'     => 'integer',
                ],
                'record_ids' => [
                    'required' => true,
                    'type'     => 'array',
                ],
                'resource' => [
                    'required'          => true,
                    'type'              => 'string',
                    'validate_callback' => fn($v) => in_array($v, ['product', 'order'], true),
                ],
            ],
        ]
    );
});

/**
 *  /accesses/update
 */
add_action('rest_api_init', function (): void {

    register_rest_route(
        'apprentice/v1',
        '/accesses/update',
        [
            'methods'             => 'PATCH',
            'callback'            => 'update_user_access',
            'permission_callback' => function (): bool {
                return current_user_can('list_users');
            },
            'args' => [
                'user_id' => [
                    'required' => true,
                    'type'     => 'integer',
                ],
                'record_id' => [
                    'required' => true,
                    'type'     => 'integer',
                ],
                'resource' => [
                    'required'          => true,
                    'type'              => 'string',
                    'validate_callback' => fn($v) => in_array($v, ['product', 'order'], true),
                ],
                'expires_at' => [
                    'required' => true,
                    'type'     => 'string',
                ],
            ],
        ]
    );
});

/**
 *  /accesses/delete
 */
add_action('rest_api_init', function (): void {

    register_rest_route(
        'apprentice/v1',
        '/accesses/delete',
        [
            'methods'             => 'DELETE',
            'callback'            => 'delete_user_accesses',
            'permission_callback' => function (): bool {
                return current_user_can('list_users');
            },
            'args' => [
                'user_id' => [
                    'required' => true,
                    'type'     => 'integer',
                ],
                'record_ids' => [
                    'required' => true,
                    'type'     => 'array',
                ],
                'resource' => [
                    'required'          => true,
                    'type'              => 'string',
                    'validate_callback' => fn($v) => in_array($v, ['product', 'order'], true),
                ],
            ],
        ]
    );
});

/**
 *  /users/delete
 */
add_action('rest_api_init', function (): void {

    register_rest_route(
        'apprentice/v1',
        '/users/delete',
        [
            'methods'             => 'POST',
            'callback'            => 'delete_wp_user',
            'permission_callback' => function (): bool {
                return current_user_can('list_users');
            },
            'args' => [
                'user_id' => [
                    'required' => true,
                    'type'     => 'integer',
                ],
            ],
        ]
    );
});


/* - - -  F U N C T I O N S  - - - */

function revoke_user_accesses(WP_REST_Request $request): WP_Error | array
{
    global $wpdb;

    $params = $request->get_json_params();

    // Validate user_id
    $user_id = parse_user_id_param($params['user_id'] ?? null);

    if ($user_id instanceof WP_Error) {
        return $user_id;
    }

    // Validate record_ids
    $record_ids = parse_record_ids_param($params['record_ids'] ?? null);

    if ($record_ids instanceof WP_Error) {
        return $record_ids;
    }

    // Validate resource
    $resource = $params['resource'] ?? '';

    if (! in_array($resource, ['product', 'order'], true)) {
        return new WP_Error(
            'invalid_resource',
            "resource must be 'product' or 'order'",
            ['status' => 400]
        );
    }

    $context = build_semantic_context(
        $user_id,
        $resource,
        $record_ids,
        order_status: 1,
        item_status: 1
    );

    if ($context instanceof WP_Error) {
        return $context;
    }

    $matched = $context;

    // Batch update orders
    $order_placeholders = implode(',', array_fill(0, count($matched['order_ids']), '%d'));

    $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$wpdb->prefix}tva_orders SET status = 4 WHERE ID IN ($order_placeholders)",
            ...$matched['order_ids']
        )
    );

    $orders_updated = $wpdb->rows_affected;

    // Batch update order items
    $item_placeholders = implode(',', array_fill(0, count($matched['item_ids']), '%d'));

    $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$wpdb->prefix}tva_order_items SET status = 0 WHERE id IN ($item_placeholders)",
            ...$matched['item_ids']
        )
    );

    $items_updated = $wpdb->rows_affected;

    return [
        'message'        => 'success',
        'orders_updated' => $orders_updated,
        'items_updated'  => $items_updated,
    ];
}

function restore_user_accesses(WP_REST_Request $request): WP_Error | array
{
    global $wpdb;

    $params = $request->get_json_params();

    // Validate user_id
    $user_id = parse_user_id_param($params['user_id'] ?? null);

    if ($user_id instanceof WP_Error) {
        return $user_id;
    }

    // Validate record_ids
    $record_ids = parse_record_ids_param($params['record_ids'] ?? null);

    if ($record_ids instanceof WP_Error) {
        return $record_ids;
    }

    // Validate resource
    $resource = $params['resource'] ?? '';

    if (! in_array($resource, ['product', 'order'], true)) {
        return new WP_Error(
            'invalid_resource',
            "resource must be 'product' or 'order'",
            ['status' => 400]
        );
    }

    $context = build_semantic_context(
        $user_id,
        $resource,
        $record_ids,
        order_status: 4,
        item_status: 0
    );

    if ($context instanceof WP_Error) {
        return $context;
    }

    $matched = $context;

    // Batch restore orders to active
    $order_placeholders = implode(',', array_fill(0, count($matched['order_ids']), '%d'));

    $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$wpdb->prefix}tva_orders SET status = 1 WHERE ID IN ($order_placeholders)",
            ...$matched['order_ids']
        )
    );

    $orders_updated = $wpdb->rows_affected;

    // Batch restore order items to active
    $item_placeholders = implode(',', array_fill(0, count($matched['item_ids']), '%d'));

    $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$wpdb->prefix}tva_order_items SET status = 1 WHERE id IN ($item_placeholders)",
            ...$matched['item_ids']
        )
    );

    $items_updated = $wpdb->rows_affected;

    return [
        'message'        => 'success',
        'orders_updated' => $orders_updated,
        'items_updated'  => $items_updated,
    ];
}

function delete_user_accesses(WP_REST_Request $request): WP_Error | array
{
    global $wpdb;

    $params = $request->get_json_params();

    // Validate user_id
    $user_id = parse_user_id_param($params['user_id'] ?? null);

    if ($user_id instanceof WP_Error) {
        return $user_id;
    }

    // Validate record_ids
    $record_ids = parse_record_ids_param($params['record_ids'] ?? null);

    if ($record_ids instanceof WP_Error) {
        return $record_ids;
    }

    // Validate resource
    $resource = $params['resource'] ?? '';

    if (! in_array($resource, ['product', 'order'], true)) {
        return new WP_Error(
            'invalid_resource',
            "resource must be 'product' or 'order'",
            ['status' => 400]
        );
    }

    $context = build_semantic_context($user_id, $resource, $record_ids);

    if ($context instanceof WP_Error) {
        return $context;
    }

    $matched     = $context;
    $product_ids = $context['product_ids'];

    $orders_deleted = 0;
    $items_deleted  = 0;

    // Delete items before orders (FK safety)
    $item_placeholders = implode(',', array_fill(0, count($matched['item_ids']), '%d'));

    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}tva_order_items WHERE id IN ($item_placeholders)",
            ...$matched['item_ids']
        )
    );

    $items_deleted = $wpdb->rows_affected;

    $order_placeholders = implode(',', array_fill(0, count($matched['order_ids']), '%d'));

    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}tva_orders WHERE ID IN ($order_placeholders)",
            ...$matched['order_ids']
        )
    );

    $orders_deleted = $wpdb->rows_affected;

    if (! empty($product_ids)) {
        // Delete access history entries for this user + product IDs
        $product_placeholders = implode(',', array_fill(0, count($product_ids), '%d'));

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}tva_access_history WHERE user_id = %d AND product_id IN ($product_placeholders)",
                $user_id,
                ...$product_ids
            )
        );

        $history_deleted = $wpdb->rows_affected;

        // Delete usermeta expiry entries for this user + product IDs
        $meta_keys         = array_map(fn(int $id) => "tva_product_{$id}_access_expiry", $product_ids);
        $meta_placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key IN ($meta_placeholders)",
                $user_id,
                ...$meta_keys
            )
        );

        $usermeta_deleted = $wpdb->rows_affected;
    } else {
        $history_deleted  = 0;
        $usermeta_deleted = 0;
    }

    return [
        'message'          => 'success',
        'orders_deleted'   => $orders_deleted,
        'items_deleted'    => $items_deleted,
        'history_deleted'  => $history_deleted,
        'usermeta_deleted' => $usermeta_deleted,
    ];
}

function delete_wp_user(WP_REST_Request $request): WP_Error | array
{
    global $wpdb;

    $params = $request->get_json_params();

    $user_id = parse_user_id_param($params['user_id'] ?? null);

    if ($user_id instanceof WP_Error) {
        return $user_id;
    }

    $user = get_user_by('id', $user_id);

    if (! $user) {
        return new WP_Error(
            'user_not_found',
            "No user found for user_id {$user_id}.",
            ['status' => 404]
        );
    }

    if (in_array('administrator', $user->roles, true)) {
        return new WP_Error(
            'cannot_delete_admin',
            'Deleting users with the administrator role is not allowed.',
            ['status' => 422]
        );
    }

    if (get_current_user_id() === $user_id) {
        return new WP_Error(
            'cannot_delete_self',
            'You cannot delete your own account via this endpoint.',
            ['status' => 422]
        );
    }

    // Delete order items before orders (FK safety)
    $order_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT ID FROM {$wpdb->prefix}tva_orders WHERE user_id = %d",
            $user_id
        )
    );

    $items_deleted = 0;

    if (! empty($order_ids)) {
        $order_placeholders = implode(',', array_fill(0, count($order_ids), '%d'));

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}tva_order_items WHERE order_id IN ($order_placeholders)",
                ...$order_ids
            )
        );

        $items_deleted = $wpdb->rows_affected;
    }

    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}tva_orders WHERE user_id = %d",
            $user_id
        )
    );

    $orders_deleted = $wpdb->rows_affected;

    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}tva_access_history WHERE user_id = %d",
            $user_id
        )
    );

    $history_deleted = $wpdb->rows_affected;

    // wp_delete_user removes the wp_users row, all usermeta, and the user's posts
    if (! wp_delete_user($user_id)) {
        return new WP_Error(
            'user_delete_failed',
            'wp_delete_user() returned false — the user could not be deleted.',
            ['status' => 500]
        );
    }

    return [
        'message'         => 'success',
        'orders_deleted'  => $orders_deleted,
        'items_deleted'   => $items_deleted,
        'history_deleted' => $history_deleted,
        'user_deleted'    => true,
    ];
}

function update_user_access(WP_REST_Request $request): WP_Error | array
{
    global $wpdb;

    $params = $request->get_json_params();

    // Validate user_id
    $user_id = parse_user_id_param($params['user_id'] ?? null);

    if ($user_id instanceof WP_Error) {
        return $user_id;
    }

    // Validate record_id
    $record_id = parse_record_id_param($params['record_id'] ?? null);

    if ($record_id instanceof WP_Error) {
        return $record_id;
    }

    // Validate resource
    $resource = $params['resource'] ?? '';

    if (! in_array($resource, ['product', 'order'], true)) {
        return new WP_Error(
            'invalid_resource',
            "resource must be 'product' or 'order'",
            ['status' => 400]
        );
    }

    // Validate and normalize expires_at
    $expires_at_raw = $params['expires_at'] ?? '';

    if (empty($expires_at_raw)) {
        return new WP_Error(
            'invalid_expires_at',
            "The 'expires_at' parameter is required.",
            ['status' => 400]
        );
    }

    $parsed = strtotime($expires_at_raw);

    if ($parsed === false) {
        return new WP_Error(
            'invalid_expires_at',
            "The 'expires_at' parameter must be a valid date or datetime string.",
            ['status' => 400]
        );
    }

    $expires_at = date('Y-m-d H:i:s', $parsed);

    $context = build_semantic_context($user_id, $resource, [$record_id]);

    if ($context instanceof WP_Error) {
        return $context;
    }

    $product_ids = $context['product_ids'];

    // Batch-fetch expiry configs and verify every target product allows custom expiry.
    // Only 'after_purchase' products store a per-user date in usermeta — all other
    // modes ('unlimited', 'specific_time', 'not_configured', 'other') use a fixed or
    // absent expiry that must not be overridden by this endpoint.
    $expiry_ph      = implode(',', array_fill(0, count($product_ids), '%d'));
    $expiry_rows    = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT term_id, meta_value FROM {$wpdb->termmeta} WHERE term_id IN ($expiry_ph) AND meta_key = 'access_expiry'",
            ...$product_ids
        ),
        ARRAY_A
    );

    $expiry_configs = [];

    foreach ($expiry_rows as $row) {
        $expiry_configs[(int) $row['term_id']] = $row['meta_value'];
    }

    foreach ($product_ids as $product_id) {
        $expiry_info = parse_product_expiry($product_id, $expiry_configs);

        if ($expiry_info['mode'] !== 'after_purchase') {
            return new WP_Error(
                'product_expiry_cannot_be_updated',
                sprintf(
                    "Product %d uses expiry mode '%s'. Only 'after_purchase' products support a custom per-user expiry date.",
                    $product_id,
                    $expiry_info['mode']
                ),
                ['status' => 422]
            );
        }
    }

    $has_updated = false;
    $has_created = false;

    foreach ($product_ids as $product_id) {
        $meta_key = "tva_product_{$product_id}_access_expiry";

        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s LIMIT 1",
                $user_id,
                $meta_key
            )
        );

        if ($existing !== null) {
            if ((string) $existing === $expires_at) {
                continue;
            }

            $result = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$wpdb->usermeta} SET meta_value = %s WHERE user_id = %d AND meta_key = %s",
                    $expires_at,
                    $user_id,
                    $meta_key
                )
            );

            if ($result === false) {
                return new WP_Error(
                    'db_write_failed',
                    'Failed to update expiry value.',
                    ['status' => 500]
                );
            }

            $has_updated = true;
            continue;
        }

        $result = $wpdb->insert(
            $wpdb->usermeta,
            [
                'user_id'    => $user_id,
                'meta_key'   => $meta_key,
                'meta_value' => $expires_at,
            ],
            ['%d', '%s', '%s']
        );

        if ($result === false) {
            return new WP_Error(
                'db_write_failed',
                'Failed to create expiry value.',
                ['status' => 500]
            );
        }

        $has_created = true;
    }

    // Keep a deterministic top-level outcome for multi-product order mode.
    if ($has_updated) {
        $info = 'expiry_updated';
    } elseif ($has_created) {
        $info = 'expiry_created';
    } else {
        $info = 'expiry_unchanged';
    }

    return [
        'message' => 'success',
        'info'    => $info,
    ];
}

function get_accesses_by_user_ids(WP_REST_Request $request): WP_Error | array
{
    global $wpdb;

    $params = $request->get_json_params();

    $user_ids = $params['user_ids'];

    if (! is_array($user_ids) || empty($user_ids)) {
        return new WP_Error(
            'invalid_user_ids',
            'user_ids must be a non-empty array',
            ['status' => 400]
        );
    }

    $user_ids = array_values(array_unique(array_map('intval', $user_ids)));

    if (count($user_ids) > 100) {
        return new WP_Error(
            'too_many_user_ids',
            'Maximum of 100 user_ids allowed per request',
            ['status' => 400]
        );
    }

    // BATCH FETCH ALL TERMMETA EXPIRY CONFIGS!
    $all_product_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT DISTINCT product_id FROM {$wpdb->prefix}tva_access_history WHERE user_id IN (" . implode(',', array_fill(0, count($user_ids), '%d')) . ")",
            ...$user_ids
        )
    );

    $expiry_configs = [];

    if (!empty($all_product_ids)) {
        $placeholders = implode(',', array_fill(0, count($all_product_ids), '%d'));
        $expiry_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT term_id, meta_value FROM {$wpdb->termmeta} WHERE term_id IN ($placeholders) AND meta_key = 'access_expiry'",
                ...$all_product_ids
            ),
            ARRAY_A
        );

        foreach ($expiry_rows as $row) {
            $expiry_configs[(int) $row['term_id']] = $row['meta_value'];
        }
    }

    $results = [];

    foreach ($user_ids as $user_id) {

        $user = get_user_by('id', $user_id);

        // --------
        // USER NOT FOUND
        // --------
        if (! $user) {
            $results[] = [
                'user_id' => $user_id,
                'status'  => 'not_found',
            ];
            continue;
        }

        // --------
        // USER FOUND
        // --------

        // Access history (event log)
        $history = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT
                    product_id,
                    course_id,
                    source,
                    status,
                    created
                FROM {$wpdb->prefix}tva_access_history
                WHERE user_id = %d
                ORDER BY created ASC
                ",
                $user_id
            ),
            ARRAY_A
        );

        // Expiry lookup (current state)
        $expiry_map = [];

        $meta_rows = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT meta_key, meta_value
                FROM {$wpdb->usermeta}
                WHERE user_id = %d
                  AND meta_key LIKE 'tva_product\_%\_access_expiry'
                ",
                $user_id
            ),
            ARRAY_A
        );

        foreach ($meta_rows as $row) {
            if (preg_match('/tva_product_(\d+)_access_expiry/', $row['meta_key'], $m)) {
                $expiry_map[(int) $m[1]] = $row['meta_value'] !== ''
                    ? $row['meta_value']
                    : null;
            }
        }

        $events = transform_access_history_events(
            rows: $history,
            expiry_configs: $expiry_configs,
            expiry_map: $expiry_map,
            include_user_id: false
        );

        // Evaluate current access state
        $access_data = evaluate_current_accesses(
            $user_id,
            $expiry_configs,
            $expiry_map
        );

        $results[] = [
            'user_id'  => $user_id,
            'status'   => 'found',
            'email'    => $user->user_email,
            'roles'    => array_values($user->roles),
            'access_count' => count($access_data['accesses']),
            'accesses' => $access_data['accesses'],
            'outdated_accesses_count' => count($access_data['outdated_accesses']),
            'outdated_accesses' => $access_data['outdated_accesses'],
            'event_count' => count($events),
            'events' => $events,
        ];
    }

    return $results;
}

function get_accesses_by_time(WP_REST_Request $request): WP_Error | array
{
    global $wpdb;

    $params = $request->get_json_params();

    $parsed_params = parse_since_and_until($params);

    if ($parsed_params instanceof WP_Error) {
        return $parsed_params;
    }

    [$since, $until] = $parsed_params;

    $include_revocations = $params['include_revocations'] ?? true;

    // Query NEW orders created in the timeframe (status=1 for active grants)
    $new_orders = $wpdb->get_results(
        $wpdb->prepare(
            "
            SELECT
                o.ID AS order_id,
                o.user_id,
                o.created_at AS order_created_at,
                oi.product_id,
                o.status AS order_status,
                oi.status AS item_status
            FROM {$wpdb->prefix}tva_orders o
            JOIN {$wpdb->prefix}tva_order_items oi ON oi.order_id = o.ID
            WHERE o.created_at >= %s AND o.created_at <= %s
              AND o.status = 1
              AND oi.status = 1
            ORDER BY o.created_at ASC
            ",
            $since,
            $until
        ),
        ARRAY_A
    );

    // Query ALL revoked orders (for comparison by client) - only if requested
    $revoked_orders = [];

    if ($include_revocations) {
        $revoked_orders = $wpdb->get_results(
            "
            SELECT
                o.ID AS order_id,
                o.status,
                o.created_at
            FROM {$wpdb->prefix}tva_orders o
            WHERE o.status = 4
            AND o.user_id IN (SELECT ID FROM {$wpdb->prefix}users)
            ORDER BY o.created_at ASC
            ",
            ARRAY_A
        );
    }

    // Get unique product IDs from new orders for batch queries
    $product_ids = array_unique(array_column($new_orders, 'product_id'));

    // Batch fetch expiry configs
    $expiry_configs = [];

    if (!empty($product_ids)) {
        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
        $expiry_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT term_id, meta_value FROM {$wpdb->termmeta} WHERE term_id IN ($placeholders) AND meta_key = 'access_expiry'",
                ...$product_ids
            ),
            ARRAY_A
        );

        foreach ($expiry_rows as $row) {
            $expiry_configs[(int) $row['term_id']] = $row['meta_value'];
        }
    }

    // Batch fetch usermeta expiry dates for all users+products in new orders
    $user_product_map = [];

    if (!empty($new_orders)) {
        $user_ids = array_unique(array_column($new_orders, 'user_id'));
        $placeholders = implode(',', array_fill(0, count($user_ids), '%d'));

        $expiry_dates = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id IN ($placeholders) AND meta_key LIKE 'tva_product%%_access_expiry'",
                ...$user_ids
            ),
            ARRAY_A
        );

        foreach ($expiry_dates as $row) {
            if (preg_match('/tva_product_(\\d+)_access_expiry/', $row['meta_key'], $m)) {
                $user_id = (int) $row['user_id'];
                $product_id = (int) $m[1];
                $key = $user_id . '_' . $product_id;
                $user_product_map[$key] = $row['meta_value'] !== '' ? $row['meta_value'] : null;
            }
        }
    }

    // Batch fetch product names
    $product_names = [];

    if (!empty($product_ids)) {
        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
        $name_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT term_id, name FROM {$wpdb->terms} WHERE term_id IN ($placeholders)",
                ...$product_ids
            ),
            ARRAY_A
        );

        foreach ($name_rows as $row) {
            $product_names[(int) $row['term_id']] = $row['name'];
        }
    }

    // Get product→courses mapping
    $product_courses = get_product_courses_map($product_ids);

    // Build new grants list with full details (same structure as /accesses)
    $new_grants = [];

    foreach ($new_orders as $order) {
        $order_id = (int) $order['order_id'];
        $user_id = (int) $order['user_id'];
        $order_created_at = $order['order_created_at'];
        $product_id = (int) $order['product_id'];

        $product_name = $product_names[$product_id] ?? null;
        $courses = $product_courses[$product_id] ?? [];

        // Resolve expiry
        $resolved = resolve_access_expiry(
            $product_id,
            $expiry_configs,
            $user_product_map[$user_id . '_' . $product_id] ?? null,
            $user_id
        );

        // Determine if expired
        $access_status = 'active';
        $expires_at = $resolved['expires_at'];

        if ($expires_at !== null) {
            $now = current_time('mysql');
            if ($expires_at < $now) {
                $access_status = 'expired';
            }
        }

        // Add each course with full details
        foreach ($courses as $course) {
            $new_grants[] = [
                'user_id' => $user_id,
                'order_id' => $order_id,
                'order_created_at' => $order_created_at,
                'product_id' => $product_id,
                'product_name' => $product_name,
                'course_id' => $course['course_id'],
                'course_name' => $course['course_name'],
                'status' => $access_status,
                'expires_at' => $expires_at,
                'expiry_details' => $resolved['expiry_details'],
            ];
        }
    }

    // Format revoked orders list (minimal)
    $total_revocations = [];

    foreach ($revoked_orders as $order) {
        $total_revocations[] = [
            'id' => (int) $order['order_id'],
            'created_at' => $order['created_at'],
        ];
    }

    return [
        'since' => $since,
        'until' => $until,
        'new_grants_count' => count($new_grants),
        'new_grants' => $new_grants,
        ...$include_revocations ? [
            'total_revocations_count' => count($total_revocations),
            'total_revocations' => $total_revocations,
        ] : [],
    ];
}

function apprentice_product_course_map(): array
{
    global $wpdb;

    /**
     * -------------------------------------------------
     * 1. Build DEFINITION mapping (posts + terms)
     * -------------------------------------------------
     */

    $rows = $wpdb->get_results(
        "
        SELECT
            p.ID            AS post_id,
            p.post_content,
            t.term_id       AS product_id,
            t.name          AS product_name
        FROM {$wpdb->posts} p
        JOIN {$wpdb->term_relationships} tr
          ON tr.object_id = p.ID
        JOIN {$wpdb->terms} t
          ON t.term_id = tr.term_taxonomy_id
        WHERE p.post_type = 'tvd_content_set'
        ",
        ARRAY_A
    );

    /**
     * -------------------------------------------------
     * 1.5. Fetch ALL access_expiry configs (BATCH MODE!)
     * -------------------------------------------------
     */
    $expiry_configs = [];

    if (!empty($rows)) {
        $product_ids = array_unique(array_column($rows, 'product_id'));
        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));

        $expiry_rows = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT term_id, meta_value
                FROM {$wpdb->termmeta}
                WHERE term_id IN ($placeholders)
                  AND meta_key = 'access_expiry'
                ",
                $product_ids
            ),
            ARRAY_A
        );

        foreach ($expiry_rows as $row) {
            $expiry_configs[(int) $row['term_id']] = $row['meta_value'];
        }
    }

    $products = [];
    $definition_pairs = [];

    foreach ($rows as $row) {

        $product_id   = (int) $row['product_id'];
        $product_name = $row['product_name'];

        $course_ids = apprentice_extract_course_ids($row['post_content']);

        if (empty($course_ids)) {
            continue;
        }

        // Resolve course names from wp_terms
        $placeholders = implode(',', array_fill(0, count($course_ids), '%d'));

        $course_terms = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT term_id, name
                FROM {$wpdb->terms}
                WHERE term_id IN ($placeholders)
                ",
                $course_ids
            ),
            ARRAY_A
        );

        $courses = [];

        foreach ($course_terms as $term) {
            $course_id = (int) $term['term_id'];

            $courses[] = [
                'course_id'   => $course_id,
                'course_name' => $term['name'],
            ];

            $definition_pairs["$product_id:$course_id"] = true;
        }

        $products[] = [
            'product_id'        => $product_id,
            'product_name'      => $product_name,
            'courses'           => $courses,
            'expiry_details'    => parse_product_expiry($product_id, $expiry_configs),
        ];
    }

    /**
     * -------------------------------------------------
     * 2. Build HISTORY mapping (tva_access_history)
     * -------------------------------------------------
     */

    $history_rows = $wpdb->get_results(
        "
        SELECT DISTINCT product_id, course_id
        FROM {$wpdb->prefix}tva_access_history
        WHERE product_id IS NOT NULL
          AND course_id IS NOT NULL
        ",
        ARRAY_A
    );

    $history_pairs = [];

    foreach ($history_rows as $row) {
        $history_pairs[(int) $row['product_id'] . ':' . (int) $row['course_id']] = true;
    }

    /**
     * -------------------------------------------------
     * 3. Cross-validation
     * -------------------------------------------------
     */

    $missing_in_definition = [];
    $missing_in_history    = [];

    foreach ($history_pairs as $key => $_) {
        if (! isset($definition_pairs[$key])) {
            $missing_in_definition[] = $key;
        }
    }

    foreach ($definition_pairs as $key => $_) {
        if (! isset($history_pairs[$key])) {
            $missing_in_history[] = $key;
        }
    }

    /**
     * -------------------------------------------------
     * 4. Final response
     * -------------------------------------------------
     */

    return [
        'generated_at' => current_time('mysql'),
        'products'     => $products,
        'validation'   => [
            'missing_in_definition' => array_values($missing_in_definition),
            'missing_in_history'    => array_values($missing_in_history),
        ],
    ];
}


/* - - -  H E L P E R S  - - - */

function parse_since_and_until(array $params): WP_Error | array
{
    $since = $params['since'] ?? null;

    if (empty($since)) {
        return new WP_Error(
            'missing_since',
            "The 'since' parameter is required.",
            ['status' => 400]
        );
    }

    $parsed_since = strtotime($since);

    if ($parsed_since  === false) {
        return new WP_Error(
            'invalid_since',
            "The 'since' parameter must be a valid date or datetime string.",
            ['status' => 400]
        );
    }

    $since = date('Y-m-d H:i:s', $parsed_since);

    if (array_key_exists('until', $params)) {
        $until = $params['until'];

        if (empty($until)) {
            return new WP_Error(
                'invalid_until',
                "The 'until' parameter cannot be empty when provided.",
                ['status' => 400]
            );
        }

        $parsed_until = strtotime($until);

        if ($parsed_until === false) {
            return new WP_Error(
                'invalid_until',
                "The 'until' parameter must be a valid date or datetime string.",
                ['status' => 400]
            );
        }

        $until = date('Y-m-d H:i:s', $parsed_until);

        // If no time component in $until, set to end of day (23:59:59)
        $date_only = date('Y-m-d', $parsed_until);
        $datetime_check = date('Y-m-d H:i:s', $parsed_until);

        if ($datetime_check === $date_only . ' 00:00:00') {
            $parsed_until = strtotime($date_only . ' 23:59:59');
        }

        $until = date('Y-m-d H:i:s', $parsed_until);

        // Check that until is later than since
        if ($parsed_until <= $parsed_since) {
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

function apprentice_extract_course_ids(string $post_content): array
{
    $data = maybe_unserialize($post_content);

    if (! is_array($data)) {
        return [];
    }

    $course_ids = [];

    foreach ($data as $rule) {
        if (
            isset(
                $rule['content_type'],
                $rule['content'],
                $rule['value']
            )
            && $rule['content_type'] === 'term'
            && $rule['content'] === 'tva_courses'
            && is_array($rule['value'])
        ) {
            foreach ($rule['value'] as $course_id) {
                $course_ids[] = (int) $course_id;
            }
        }
    }

    return array_values(array_unique($course_ids));
}

function exceeds_php_int_max(mixed $value): bool
{
    if (is_float($value) && $value > PHP_INT_MAX) {
        return true;
    }

    if (is_string($value)) {
        $trimmed = trim($value);

        if (preg_match('/^\d+$/', $trimmed) === 1) {
            $normalized = ltrim($trimmed, '0');
            $normalized = $normalized === '' ? '0' : $normalized;
            $max_int    = (string) PHP_INT_MAX;

            if (
                strlen($normalized) > strlen($max_int)
                || (strlen($normalized) === strlen($max_int) && strcmp($normalized, $max_int) > 0)
            ) {
                return true;
            }
        }
    }

    return false;
}

function parse_user_id_param(mixed $raw_user_id): WP_Error | int
{
    if (exceeds_php_int_max($raw_user_id)) {
        return new WP_Error(
            'user_id_exceeds_max',
            'user_id exceeds the maximum supported integer value: ' . PHP_INT_MAX,
            ['status' => 400]
        );
    }

    $user_id = intval($raw_user_id);

    if ($user_id === 0) {
        return new WP_Error(
            'invalid_user_id',
            'user_id must be a non-zero integer',
            ['status' => 400]
        );
    }

    return $user_id;
}

function parse_record_id_param(mixed $raw_record_id): WP_Error | int
{
    if (exceeds_php_int_max($raw_record_id)) {
        return new WP_Error(
            'record_id_exceeds_max',
            'record_id exceeds the maximum supported integer value: ' . PHP_INT_MAX,
            ['status' => 400]
        );
    }

    $record_id = intval($raw_record_id);

    if ($record_id === 0) {
        return new WP_Error(
            'invalid_record_id',
            'record_id must be a non-zero integer',
            ['status' => 400]
        );
    }

    return $record_id;
}

function parse_record_ids_param(mixed $raw_record_ids): WP_Error | array
{
    if (! is_array($raw_record_ids) || empty($raw_record_ids)) {
        return new WP_Error(
            'invalid_record_ids',
            'record_ids must be a non-empty array',
            ['status' => 400]
        );
    }

    foreach ($raw_record_ids as $item) {
        if (exceeds_php_int_max($item)) {
            return new WP_Error(
                'record_id_exceeds_max',
                'One or more record_ids exceed the maximum supported integer value',
                ['status' => 400]
            );
        }
    }

    return array_values(array_unique(array_map('intval', $raw_record_ids)));
}

function check_user_exists(int $user_id): WP_Error | null
{
    if (! get_user_by('id', $user_id)) {
        return new WP_Error(
            'user_not_found',
            "No user found for user_id {$user_id}.",
            ['status' => 404]
        );
    }

    return null;
}

function check_products_exist(array $product_ids): WP_Error | null
{
    global $wpdb;

    if (empty($product_ids)) {
        return null;
    }

    $product_ids = array_values(array_unique(array_map('intval', $product_ids)));
    $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));

    $existing = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT term_id FROM {$wpdb->terms} WHERE term_id IN ($placeholders)",
            ...$product_ids
        )
    );

    $existing = array_map('intval', $existing);
    $missing  = array_values(array_diff($product_ids, $existing));

    if (! empty($missing)) {
        return new WP_Error(
            'product_not_found',
            'No product found for product_id(s): ' . implode(', ', $missing),
            ['status' => 404]
        );
    }

    return null;
}

function resolve_records_by_resource(
    int $user_id,
    string $resource,
    array $record_ids,
    ?int $order_status = null,
    ?int $item_status = null,
): WP_Error | array {
    if ($resource === 'order') {
        $matched = find_order_items_by_order_ids(
            $user_id,
            $record_ids,
            $order_status,
            $item_status
        );

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

    $matched = find_order_items_for_access_update(
        $user_id,
        $record_ids,
        $order_status,
        $item_status
    );

    return [
        'order_ids'   => $matched['order_ids'] ?? [],
        'item_ids'    => $matched['item_ids'] ?? [],
        'product_ids' => $record_ids,
    ];
}

function assert_resolved_records_exist(array $resolved): WP_Error | null
{
    if (empty($resolved['item_ids'])) {
        return new WP_Error(
            'no_orders_found',
            'No matching order records found for this request.',
            ['status' => 422]
        );
    }

    return null;
}

function build_semantic_context(
    int $user_id,
    string $resource,
    array $record_ids,
    ?int $order_status = null,
    ?int $item_status = null,
): WP_Error | array {
    $user_check = check_user_exists($user_id);

    if ($user_check instanceof WP_Error) {
        return $user_check;
    }

    if ($resource === 'product') {
        $product_check = check_products_exist($record_ids);

        if ($product_check instanceof WP_Error) {
            return $product_check;
        }
    }

    $resolved = resolve_records_by_resource(
        $user_id,
        $resource,
        $record_ids,
        $order_status,
        $item_status
    );

    if ($resolved instanceof WP_Error) {
        return $resolved;
    }

    $exists_check = assert_resolved_records_exist($resolved);

    if ($exists_check instanceof WP_Error) {
        return $exists_check;
    }

    if ($resource === 'order') {
        $product_check = check_products_exist($resolved['product_ids']);

        if ($product_check instanceof WP_Error) {
            return $product_check;
        }
    }

    return $resolved;
}

function find_order_items_for_access_update(
    int $user_id,
    array $product_ids,
    ?int $order_status = null,
    ?int $item_status = null,
): array {
    global $wpdb;

    if ($order_status !== null) {
        $order_sql  = "SELECT ID FROM {$wpdb->prefix}tva_orders WHERE user_id = %d AND status = %d";
        $order_args = [$user_id, $order_status];
    } else {
        $order_sql  = "SELECT ID FROM {$wpdb->prefix}tva_orders WHERE user_id = %d";
        $order_args = [$user_id];
    }

    $all_order_ids = $wpdb->get_col(
        $wpdb->prepare($order_sql, ...$order_args)
    );

    if (empty($all_order_ids)) {
        return ['order_ids' => [], 'item_ids' => []];
    }

    $order_placeholders   = implode(',', array_fill(0, count($all_order_ids), '%d'));
    $product_placeholders = implode(',', array_fill(0, count($product_ids), '%d'));

    if ($item_status !== null) {
        $items_sql  = "SELECT id AS item_id, order_id
            FROM {$wpdb->prefix}tva_order_items
            WHERE order_id IN ($order_placeholders)
              AND product_id IN ($product_placeholders)
              AND status = %d";
        $items_args = [...$all_order_ids, ...$product_ids, $item_status];
    } else {
        $items_sql  = "SELECT id AS item_id, order_id
            FROM {$wpdb->prefix}tva_order_items
            WHERE order_id IN ($order_placeholders)
              AND product_id IN ($product_placeholders)";
        $items_args = [...$all_order_ids, ...$product_ids];
    }

    $matched_items = $wpdb->get_results(
        $wpdb->prepare($items_sql, ...$items_args),
        ARRAY_A
    );

    if (empty($matched_items)) {
        return ['order_ids' => [], 'item_ids' => []];
    }

    return [
        'order_ids' => array_values(array_unique(array_column($matched_items, 'order_id'))),
        'item_ids'  => array_column($matched_items, 'item_id'),
    ];
}

function find_order_items_by_order_ids(
    int $user_id,
    array $order_ids,
    ?int $order_status = null,
    ?int $item_status = null,
): array {
    global $wpdb;

    $order_placeholders = implode(',', array_fill(0, count($order_ids), '%d'));

    if ($order_status !== null) {
        $order_sql  = "SELECT ID FROM {$wpdb->prefix}tva_orders WHERE ID IN ($order_placeholders) AND user_id = %d AND status = %d";
        $order_args = [...$order_ids, $user_id, $order_status];
    } else {
        $order_sql  = "SELECT ID FROM {$wpdb->prefix}tva_orders WHERE ID IN ($order_placeholders) AND user_id = %d";
        $order_args = [...$order_ids, $user_id];
    }

    $matched_order_ids = $wpdb->get_col(
        $wpdb->prepare($order_sql, ...$order_args)
    );

    if (empty($matched_order_ids)) {
        return ['order_ids' => [], 'item_ids' => [], 'product_ids' => []];
    }

    $matched_order_placeholders = implode(',', array_fill(0, count($matched_order_ids), '%d'));

    if ($item_status !== null) {
        $items_sql  = "SELECT id AS item_id, order_id, product_id
            FROM {$wpdb->prefix}tva_order_items
            WHERE order_id IN ($matched_order_placeholders)
              AND status = %d";
        $items_args = [...$matched_order_ids, $item_status];
    } else {
        $items_sql  = "SELECT id AS item_id, order_id, product_id
            FROM {$wpdb->prefix}tva_order_items
            WHERE order_id IN ($matched_order_placeholders)";
        $items_args = $matched_order_ids;
    }

    $matched_items = $wpdb->get_results(
        $wpdb->prepare($items_sql, ...$items_args),
        ARRAY_A
    );

    if (empty($matched_items)) {
        return ['order_ids' => [], 'item_ids' => [], 'product_ids' => []];
    }

    // Detect orders that contain more than one item (ambiguous — caller cannot safely target a single product)
    $items_per_order = array_count_values(array_column($matched_items, 'order_id'));
    $ambiguous       = array_keys(array_filter($items_per_order, fn(int $count) => $count > 1));

    if (! empty($ambiguous)) {
        return ['ambiguous_order_ids' => $ambiguous];
    }

    return [
        'order_ids'   => array_values(array_unique(array_column($matched_items, 'order_id'))),
        'item_ids'    => array_column($matched_items, 'item_id'),
        'product_ids' => array_values(array_unique(array_map('intval', array_column($matched_items, 'product_id')))),
    ];
}

/**
 * Get product to courses mapping with names
 *
 * @param array $product_ids Array of product IDs to get courses for
 * @return array Map of product_id => [['course_id' => X, 'course_name' => 'Y'], ...]
 */
function get_product_courses_map(array $product_ids): array
{
    global $wpdb;

    if (empty($product_ids)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));

    // Get content set definitions
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "
            SELECT
                p.post_content,
                t.term_id AS product_id
            FROM {$wpdb->posts} p
            JOIN {$wpdb->term_relationships} tr
              ON tr.object_id = p.ID
            JOIN {$wpdb->terms} t
              ON t.term_id = tr.term_taxonomy_id
            WHERE p.post_type = 'tvd_content_set'
              AND t.term_id IN ($placeholders)
            ",
            ...$product_ids
        ),
        ARRAY_A
    );

    $product_courses = [];

    foreach ($rows as $row) {
        $product_id = (int) $row['product_id'];
        $course_ids = apprentice_extract_course_ids($row['post_content']);

        if (empty($course_ids)) {
            $product_courses[$product_id] = [];
            continue;
        }

        // Fetch course names
        $course_placeholders = implode(',', array_fill(0, count($course_ids), '%d'));
        $course_terms = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT term_id, name FROM {$wpdb->terms} WHERE term_id IN ($course_placeholders)",
                ...$course_ids
            ),
            ARRAY_A
        );

        $courses = [];
        foreach ($course_terms as $term) {
            $courses[] = [
                'course_id' => (int) $term['term_id'],
                'course_name' => $term['name'],
            ];
        }

        $product_courses[$product_id] = $courses;
    }

    return $product_courses;
}


function parse_product_expiry(int $product_id, array $expiry_configs): array
{
    if (!isset($expiry_configs[$product_id])) {
        return [
            'mode' => 'not_configured',
            'message' => 'access_expiry not given for product ' . $product_id,
        ];
    }

    $expiry_data = maybe_unserialize($expiry_configs[$product_id]);

    if (!is_array($expiry_data) || !isset($expiry_data['expiry'])) {
        return [
            'mode' => 'not_configured',
            'message' => 'expiry not parsable',
        ];
    }

    // CHECK IF EXPIRY IS DISABLED - PERPETUAL ACCESS!
    $enabled = isset($expiry_data['enabled'])
        ? (int) $expiry_data['enabled']
        : 0;

    if ($enabled === 0) {
        return [
            'mode' => 'unlimited',
            'date' => null,
            'duration' => null,
        ];
    }

    $expiry = $expiry_data['expiry'];
    $cond = $expiry['cond'] ?? null;

    // SPECIFIC TIME MODE
    if ($cond === 'specific_time' && !empty($expiry['cond_datetime'])) {
        // Normalize date to include seconds (default to :59 for expiry times)
        $date = $expiry['cond_datetime'];

        // If date doesn't have seconds, add :59
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $date)) {
            $date .= ':59';
        }

        return [
            'mode' => 'specific_time',
            'date' => $date,
            'duration' => null,
        ];
    }

    // AFTER PURCHASE MODE
    if ($cond === 'after_purchase' && isset($expiry['cond_purchase'])) {
        $duration = $expiry['cond_purchase'];

        return [
            'mode' => 'after_purchase',
            'date' => null,
            'duration' => [
                'number' => (int) ($duration['number'] ?? 0),
                'unit' => $duration['unit'] ?? '',
            ],
        ];
    }

    // FALLBACK - unknown condition
    return [
        'mode' => 'other',
        'message' => 'other expiry: ' . ($cond ?? 'unknown'),
    ];
}

/**
 * Resolve expires_at date and validate based on expiry mode
 *
 * @param int $product_id The product ID
 * @param array $expiry_configs Termmeta expiry configurations
 * @param mixed $usermeta_expiry The usermeta expiry value (could be from $expiry_map or $user_product_map)
 * @param int|null $user_id Optional user ID for error messages
 * @return array ['expires_at' => string|null, 'expiry_details' => array, 'validation_error' => string|null]
 */
function resolve_access_expiry(
    int $product_id,
    array $expiry_configs,
    $usermeta_expiry,
    ?int $user_id = null,
): array {
    $expiry_info = parse_product_expiry($product_id, $expiry_configs);

    $expires_at = null;
    $validation_error = null;

    if ($expiry_info['mode'] === 'specific_time' && isset($expiry_info['date'])) {
        // For specific_time, use the date from termmeta (same for all users)
        $expires_at = $expiry_info['date'];
    } elseif ($expiry_info['mode'] === 'after_purchase') {
        // For after_purchase, use the calculated date from usermeta
        $expires_at = $usermeta_expiry;

        // SANITY CHECK: after_purchase MUST have usermeta entry
        if ($expires_at === null) {
            $user_context = $user_id !== null ? " for user {$user_id}" : "";
            $validation_error = "ERROR: after_purchase mode requires tva_product_{$product_id}_access_expiry{$user_context} but it's missing";
        }
    }
    // For unlimited or other modes, expires_at remains null

    return [
        'expires_at' => $expires_at,
        'expiry_details' => $expiry_info,
        'validation_error' => $validation_error,
    ];
}

/**
 * Transform a tva_access_history row into standardized output format
 *
 * @param array $row The database row from tva_access_history
 * @param array $expiry_configs Termmeta expiry configurations
 * @param mixed $usermeta_expiry The usermeta expiry value for this product
 * @param int|null $user_id Optional user ID for validation messages
 * @param bool $include_user_id Whether to include user_id in the output
 * @return array Transformed access/event data
 */
function transform_access_history_row(
    array $row,
    array $expiry_configs,
    mixed $usermeta_expiry,
    ?int $user_id = null,
    bool $include_user_id = false
): array {
    $product_id = (int) $row['product_id'];

    $resolved = resolve_access_expiry(
        $product_id,
        $expiry_configs,
        $usermeta_expiry,
        $user_id
    );

    // Handle course_id: null if null, otherwise cast to int
    $course_id = is_null($row['course_id']) ? null : (int) $row['course_id'];

    $result = [
        'product_id' => $product_id,
        'course_id'  => $course_id,
        'created_at' => $row['created'],
        'expires_at' => $resolved['expires_at'],
        'expiry_details' => $resolved['expiry_details'],
        'source'     => $row['source'],
        'status'     => (int) $row['status'],
    ];

    // Conditionally add user_id (for /accesses/since)
    if ($include_user_id && $user_id !== null) {
        $result = ['user_id' => $user_id] + $result;
    }

    // Add validation error if present
    if ($resolved['validation_error'] !== null) {
        $result['validation_error'] = $resolved['validation_error'];
    }

    return $result;
}

/**
 * Transform multiple tva_access_history rows into standardized output format
 *
 * @param array $rows Array of database rows from tva_access_history
 * @param array $expiry_configs Termmeta expiry configurations
 * @param array $expiry_map Map of expiry values (product_id => value OR user_id_product_id => value)
 * @param bool $include_user_id Whether to include user_id in the output
 * @return array Transformed access/event data array
 */
function transform_access_history_events(
    array $rows,
    array $expiry_configs,
    array $expiry_map,
    bool $include_user_id = false
): array {
    $results = [];

    foreach ($rows as $row) {
        $product_id = (int) $row['product_id'];
        $user_id = isset($row['user_id']) ? (int) $row['user_id'] : null;

        // Build lookup key based on whether we have user context
        if ($include_user_id && $user_id !== null) {
            $expiry_key = $user_id . '_' . $product_id;
        } else {
            $expiry_key = $product_id;
        }

        $results[] = transform_access_history_row(
            $row,
            $expiry_configs,
            $expiry_map[$expiry_key] ?? null,
            $user_id,
            $include_user_id
        );
    }

    return $results;
}

/**
 * Evaluate current access state for a user based on orders and expiry
 *
 * @param int $user_id The user ID
 * @param array $expiry_configs Termmeta expiry configurations
 * @param array $expiry_map User's expiry dates map (product_id => date)
 * @return array Current access state per order-product-course combination
 */
function evaluate_current_accesses(
    int $user_id,
    array $expiry_configs,
    array $expiry_map,
): array {
    global $wpdb;

    // Query orders and order items for the user (keep ALL orders separately)
    $order_items = $wpdb->get_results(
        $wpdb->prepare(
            "
            SELECT
                o.ID AS order_id,
                o.created_at AS order_created_at,
                oi.product_id,
                o.status AS order_status,
                oi.status AS item_status
            FROM {$wpdb->prefix}tva_orders o
            JOIN {$wpdb->prefix}tva_order_items oi ON oi.order_id = o.ID
            WHERE o.user_id = %d
            ",
            $user_id
        ),
        ARRAY_A
    );

    // Get all unique product IDs for batch queries
    $product_ids = array_unique(array_column($order_items, 'product_id'));

    // Fetch product names from terms table
    $product_names = [];

    if (!empty($product_ids)) {
        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
        $name_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT term_id, name FROM {$wpdb->terms} WHERE term_id IN ($placeholders)",
                ...$product_ids
            ),
            ARRAY_A
        );

        foreach ($name_rows as $row) {
            $product_names[(int) $row['term_id']] = $row['name'];
        }
    }

    // Get product→courses mapping
    $product_courses = get_product_courses_map($product_ids);

    // Build final access lists (course-level) - separate active from outdated
    // Process EACH order item separately (no deduplication)
    $active_accesses = [];
    $outdated_accesses = [];

    foreach ($order_items as $item) {
        $order_id = (int) $item['order_id'];
        $order_created_at = $item['order_created_at'];
        $product_id = (int) $item['product_id'];
        $order_status = (int) $item['order_status'];
        $item_status = (int) $item['item_status'];

        $product_name = $product_names[$product_id] ?? null;
        $courses = $product_courses[$product_id] ?? [];

        // Check if order is active (both status = 1)
        $is_active = ($order_status === 1 && $item_status === 1);

        // Always resolve expiry info (even for revoked orders, to show what would have been)
        $resolved = resolve_access_expiry(
            $product_id,
            $expiry_configs,
            $expiry_map[$product_id] ?? null,
            $user_id
        );

        // Determine base access status from order
        if (!$is_active) {
            // Order is revoked - add all courses to outdated list
            // Show what the expiry would have been
            foreach ($courses as $course) {
                $outdated_accesses[] = [
                    'order_id' => $order_id,
                    'product_id' => $product_id,
                    'product_name' => $product_name,
                    'course_id' => $course['course_id'],
                    'course_name' => $course['course_name'],
                    'status' => 'revoked',
                    'order_created_at' => $order_created_at,
                    'expires_at' => $resolved['expires_at'],
                    'expiry_details' => $resolved['expiry_details'],
                ];
            }
            continue;
        }

        // Order is active, check expiry
        $access_status = 'active';
        $expires_at = $resolved['expires_at'];

        if ($expires_at !== null) {
            $now = current_time('mysql');
            if ($expires_at < $now) {
                $access_status = 'expired';
            }
        }

        // Add each course to appropriate list based on status
        foreach ($courses as $course) {
            $course_access = [
                'order_id' => $order_id,
                'product_id' => $product_id,
                'product_name' => $product_name,
                'course_id' => $course['course_id'],
                'course_name' => $course['course_name'],
                'status' => $access_status,
                'order_created_at' => $order_created_at,
                'expires_at' => $expires_at,
                'expiry_details' => $resolved['expiry_details'],
            ];

            if ($access_status === 'active') {
                $active_accesses[] = $course_access;
            } else {
                $outdated_accesses[] = $course_access;
            }
        }
    }

    return [
        'accesses' => $active_accesses,
        'outdated_accesses' => $outdated_accesses,
        'outdated_accesses_count' => count($outdated_accesses),
    ];
}
