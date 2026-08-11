<?php

declare(strict_types=1);

/**
 * Plugin Name: Thrive Apprentice API
 * Description: Exposes Thrive Apprentice access data via REST API. Supports reading access history and
 *              current access state per user (/accesses), querying accesses by time range (/accesses/since),
 *              retrieving the product-to-course map (/product-course-map), and writing access changes:
 *              revoke (/accesses/revoke), restore (/accesses/restore), update expiry (/accesses/update),
 *              and permanently delete access records (/accesses/delete).
 * Version: 3.0.0
 */

if (! defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

(new ThriveApi\Plugin())->boot();
