<?php

declare(strict_types=1);

/**
 * Plugin Name: Thrive Apprentice API
 * Description: Exposes Thrive Apprentice access history and state per user.
 * Version: 1.5.0
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


/* - - -  F U N C T I O N S  - - - */

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
        $accesses = evaluate_current_accesses(
            $user_id,
            $expiry_configs,
            $expiry_map
        );

        $results[] = [
            'user_id'  => $user_id,
            'status'   => 'found',
            'email'    => $user->user_email,
            'roles'    => array_values($user->roles),
            'accesses' => $accesses,
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

    $table = $wpdb->prefix . 'tva_access_history';

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$table} WHERE created >= \"%s\" AND created <= \"%s\" ORDER BY created ASC",
            $since,
            $until
        ),
        ARRAY_A
    );

    // BATCH FETCH TERMMETA EXPIRY CONFIGS FOR ALL PRODUCTS!
    $unique_product_ids = array_unique(array_column($rows, 'product_id'));
    $expiry_configs = [];

    if (!empty($unique_product_ids)) {
        $placeholders = implode(',', array_fill(0, count($unique_product_ids), '%d'));
        $expiry_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT term_id, meta_value FROM {$wpdb->termmeta} WHERE term_id IN ($placeholders) AND meta_key = 'access_expiry'",
                ...$unique_product_ids
            ),
            ARRAY_A
        );

        foreach ($expiry_rows as $row) {
            $expiry_configs[(int) $row['term_id']] = $row['meta_value'];
        }
    }

    // BATCH FETCH USERMETA EXPIRY DATES FOR ALL USER+PRODUCT COMBOS!
    $user_product_map = [];

    if (!empty($rows)) {
        // Build list of unique user_id + product_id pairs
        $user_ids = array_unique(array_column($rows, 'user_id'));

        // Fetch all expiry dates for these users
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

    $events = transform_access_history_events(
        rows: $rows,
        expiry_configs: $expiry_configs,
        expiry_map: $user_product_map,
        include_user_id: true,
    );

    return [
        'since' => $since,
        'until' => $until,
        'event_count' => count($events),
        'events' => $events,
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
            'product_id'   => $product_id,
            'product_name' => $product_name,
            'courses'      => $courses,
            'expiry'       => parse_product_expiry($product_id, $expiry_configs),
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

function parse_since_and_until($params)
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

function apprentice_extract_course_ids($post_content)
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


function parse_product_expiry($product_id, $expiry_configs)
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
 * @return array Current access state per product
 */
function evaluate_current_accesses(
    int $user_id,
    array $expiry_configs,
    array $expiry_map,
): array {
    global $wpdb;

    // Query orders and order items for the user
    $order_items = $wpdb->get_results(
        $wpdb->prepare(
            "
            SELECT
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

    // Group by product_id to handle multiple orders for same product
    $product_access = [];

    foreach ($order_items as $item) {
        $product_id = (int) $item['product_id'];
        $order_status = (int) $item['order_status'];
        $item_status = (int) $item['item_status'];

        // Check if order is active (both status = 1)
        $is_active = ($order_status === 1 && $item_status === 1);

        // If we haven't seen this product yet, or if we found an active order
        if (!isset($product_access[$product_id]) || $is_active) {
            $product_access[$product_id] = [
                'is_active' => $is_active,
                'order_status' => $order_status,
                'item_status' => $item_status,
            ];
        }
    }

    // Build final access list
    $accesses = [];

    foreach ($product_access as $product_id => $order_info) {
        // Determine base access status
        if (!$order_info['is_active']) {
            // Order is revoked
            $accesses[] = [
                'product_id' => $product_id,
                'status' => 'revoked',
                'expires_at' => null,
                'expiry_details' => null,
            ];
            continue;
        }

        // Order is active, check expiry
        $resolved = resolve_access_expiry(
            $product_id,
            $expiry_configs,
            $expiry_map[$product_id] ?? null,
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

        $accesses[] = [
            'product_id' => $product_id,
            'status' => $access_status,
            'expires_at' => $expires_at,
            'expiry_details' => $resolved['expiry_details'],
        ];
    }

    return $accesses;
}
