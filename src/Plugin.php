<?php

declare(strict_types=1);

namespace ThriveApi;

use ThriveApi\Controllers\AccessMutationController;
use ThriveApi\Controllers\AccessReadController;
use ThriveApi\Controllers\ProductController;
use ThriveApi\Repositories\OrderRepository;
use ThriveApi\Repositories\ProductRepository;
use ThriveApi\Services\AccessService;

class Plugin
{
    public function boot(): void
    {
        global $wpdb;

        $orders   = new OrderRepository($wpdb);
        $products = new ProductRepository($wpdb);
        $service  = new AccessService($orders, $products);

        $read     = new AccessReadController($service, $orders, $products);
        $mutation = new AccessMutationController($service, $orders, $products);
        $product  = new ProductController($service);

        add_action('rest_api_init', function () use ($read, $mutation, $product): void {
            $this->registerRoutes($read, $mutation, $product);
        });
    }

    private function registerRoutes(
        AccessReadController $read,
        AccessMutationController $mutation,
        ProductController $product,
    ): void {
        $ns   = 'apprentice/v1';
        $perm = fn() => current_user_can('list_users');

        register_rest_route($ns, '/accesses', [
            'methods'             => 'POST',
            'callback'            => [$read, 'byUserIds'],
            'permission_callback' => $perm,
            'args'                => [
                'user_ids' => ['required' => false, 'type' => 'array'],
            ],
        ]);

        register_rest_route($ns, '/accesses/since', [
            'methods'             => 'POST',
            'callback'            => [$read, 'byTime'],
            'permission_callback' => $perm,
            'args'                => [
                'since'               => ['required' => false, 'type' => 'string'],
                'until'               => ['required' => false, 'type' => 'string'],
                'include_revocations' => ['required' => false, 'type' => 'boolean'],
            ],
        ]);

        register_rest_route($ns, '/product-course-map', [
            'methods'             => 'GET',
            'callback'            => [$product, 'courseMap'],
            'permission_callback' => $perm,
        ]);

        $mutationArgs = [
            'user_id'    => ['required' => true, 'type' => 'integer'],
            'record_ids' => ['required' => true, 'type' => 'array'],
            'resource'   => [
                'required'          => true,
                'type'              => 'string',
                'validate_callback' => fn($v) => in_array($v, ['product', 'order'], true),
            ],
        ];

        register_rest_route($ns, '/accesses/restore', [
            'methods'             => 'PATCH',
            'callback'            => [$mutation, 'restore'],
            'permission_callback' => $perm,
            'args'                => $mutationArgs,
        ]);

        register_rest_route($ns, '/accesses/revoke', [
            'methods'             => 'PATCH',
            'callback'            => [$mutation, 'revoke'],
            'permission_callback' => $perm,
            'args'                => $mutationArgs,
        ]);

        register_rest_route($ns, '/accesses/update', [
            'methods'             => 'PATCH',
            'callback'            => [$mutation, 'update'],
            'permission_callback' => $perm,
            'args'                => [
                'user_id'    => ['required' => true, 'type' => 'integer'],
                'record_id'  => ['required' => true, 'type' => 'integer'],
                'resource'   => [
                    'required'          => true,
                    'type'              => 'string',
                    'validate_callback' => fn($v) => in_array($v, ['product', 'order'], true),
                ],
                'expires_at' => ['required' => true, 'type' => 'string'],
            ],
        ]);

        register_rest_route($ns, '/accesses/delete', [
            'methods'             => 'DELETE',
            'callback'            => [$mutation, 'delete'],
            'permission_callback' => $perm,
            'args'                => $mutationArgs,
        ]);
    }
}
