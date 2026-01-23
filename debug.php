<?php
/**
 * Plugin Name: Thrive Apprentice Introspection Debug
 */

add_action( 'rest_api_init', function () {

    register_rest_route(
        'apprentice/v1',
        'debug',
        [
            'methods'  => 'GET',
            'callback' => 'debug_thrive_apprentice',
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
            },
        ]
    );

});

function debug_thrive_apprentice() {
    global $wpdb, $wp_filter;

    // 1. Loaded classes
    $classes = get_declared_classes();
    $tva_classes = array_values( array_filter( $classes, function ( $c ) {
        return stripos( $c, 'tva' ) !== false || stripos( $c, 'thrive' ) !== false;
    }));

    // 2. Global functions
    $functions = get_defined_functions();
    $tva_functions = array_values( array_filter( $functions['user'], function ( $f ) {
        return stripos( $f, 'tva' ) !== false || stripos( $f, 'thrive' ) !== false;
    }));

    // 3. Registered post types
    $post_types = get_post_types( [], 'objects' );
    $tva_post_types = [];

    foreach ( $post_types as $pt ) {
        if (
            stripos( $pt->name, 'tva' ) !== false ||
            stripos( $pt->name, 'thrive' ) !== false
        ) {
            $tva_post_types[] = [
                'name'  => $pt->name,
                'label' => $pt->label,
            ];
        }
    }

    // 4. Example course-like posts
    $possible_courses = $wpdb->get_results("
        SELECT ID, post_type, post_title
        FROM {$wpdb->posts}
        WHERE post_type LIKE '%course%'
        OR post_type LIKE '%tva%'
        LIMIT 10
    ", ARRAY_A );

    // 5. Example lesson-like posts
    $possible_lessons = $wpdb->get_results("
        SELECT ID, post_type, post_title
        FROM {$wpdb->posts}
        WHERE post_type LIKE '%lesson%'
        OR post_type LIKE '%tva%'
        LIMIT 10
    ", ARRAY_A );

    // 6. Meta keys used by Thrive posts
    $meta_keys = $wpdb->get_col("
        SELECT DISTINCT meta_key
        FROM {$wpdb->postmeta}
        WHERE meta_key LIKE '%tva%'
        OR meta_key LIKE '%thrive%'
        LIMIT 50
    ");

    // 7. User meta keys
    $user_meta_keys = $wpdb->get_col("
        SELECT DISTINCT meta_key
        FROM {$wpdb->usermeta}
        WHERE meta_key LIKE '%tva%'
        OR meta_key LIKE '%thrive%'
        OR meta_key LIKE '%course%'
        OR meta_key LIKE '%access%'
        LIMIT 50
    ");

    // 8. Filters / actions mentioning access
    $hooks = [];

    foreach ( $wp_filter as $hook_name => $hook ) {
        if (
            stripos( $hook_name, 'access' ) !== false ||
            stripos( $hook_name, 'tva' ) !== false ||
            stripos( $hook_name, 'thrive' ) !== false
        ) {
            $hooks[] = $hook_name;
        }
    }

    return [
        'classes'        => $tva_classes,
        'functions'      => $tva_functions,
        'post_types'     => $tva_post_types,
        'sample_courses' => $possible_courses,
        'sample_lessons' => $possible_lessons,
        'post_meta_keys' => $meta_keys,
        'user_meta_keys' => $user_meta_keys,
        'hooks'          => $hooks,
    ];
}
