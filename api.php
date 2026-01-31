<?php

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
add_action('rest_api_init', function () {

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
            'permission_callback' => function () {
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
add_action('rest_api_init', function () {

    register_rest_route(
        'apprentice/v1',
        '/product-course-map',
        [
            'methods'             => 'GET',
            'callback'            => 'apprentice_product_course_map',
            'permission_callback' => function () {
                return current_user_can('list_users');
            },
        ]
    );
});


/* - - -  F U N C T I O N S  - - - */

function get_accesses_by_user_ids(WP_REST_Request $request)
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

        $accesses = [];

        foreach ($history as $entry) {

            $product_id = (int) $entry['product_id'];

            $expiry_info = parse_product_expiry($product_id, $expiry_configs);

            // Determine expires_at based on expiry mode
            $expires_at = null;

            if ($expiry_info['mode'] === 'specific_time' && isset($expiry_info['date'])) {
                // For specific_time, use the date from termmeta (same for all users)
                $expires_at = $expiry_info['date'];
            } elseif ($expiry_info['mode'] === 'after_purchase') {
                // For after_purchase, use the calculated date from usermeta
                $expires_at = $expiry_map[$product_id] ?? null;
            }
            // For perpetual or other modes, expires_at remains null

            $accesses[] = [
                'product_id' => $product_id,
                'course_id'  => (int) $entry['course_id'],
                'granted_at' => $entry['created'],
                'expires_at' => $expires_at,
                'expiry_mode' => $expiry_info['mode'],
                'expiry_details' => $expiry_info,
                'source'     => $entry['source'],
                'status'     => (int) $entry['status'],
            ];
        }

        $results[] = [
            'user_id'  => $user_id,
            'status'   => 'found',
            'email'    => $user->user_email,
            'roles'    => array_values($user->roles),
            'accesses' => $accesses,
        ];
    }

    return $results;
}

function get_accesses_by_time(WP_REST_Request $request)
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

    $events = array_map(function ($row) use ($expiry_configs, $user_product_map) {
        $product_id = (int) $row['product_id'];
        $user_id = (int) $row['user_id'];
        $key = $user_id . '_' . $product_id;

        $expiry_info = parse_product_expiry($product_id, $expiry_configs);

        return [
            'user_id'    => $user_id,
            'product_id' => $product_id,
            'course_id'  => (int) $row['course_id'],
            'status'     => (int) $row['status'],
            'source'     => $row['source'],
            'created_at' => $row['created'],
            'expires_at' => $user_product_map[$key] ?? null,
            'expiry_mode' => $expiry_info['mode'],
            'expiry_details' => $expiry_info,
        ];
    }, $rows);

    return [
        'since' => $since,
        'until' => $until,
        'events' => $events,
    ];
}

function apprentice_product_course_map()
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
        return [
            'mode' => 'specific_time',
            'date' => $expiry['cond_datetime'],
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
