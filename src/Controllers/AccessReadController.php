<?php

declare(strict_types=1);

namespace ThriveApi\Controllers;

use WP_Error;
use WP_REST_Request;
use ThriveApi\Repositories\OrderRepository;
use ThriveApi\Repositories\ProductRepository;
use ThriveApi\Services\AccessService;
use ThriveApi\Support\ParamParser;

class AccessReadController
{
    public function __construct(
        private AccessService $service,
        private OrderRepository $orders,
        private ProductRepository $products,
    ) {}

    public function byUserIds(WP_REST_Request $request): WP_Error|array
    {
        $params  = $request->get_json_params();
        $userIds = $params['user_ids'] ?? null;

        if (! is_array($userIds) || empty($userIds)) {
            return new WP_Error('invalid_user_ids', 'user_ids must be a non-empty array', ['status' => 400]);
        }

        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        if (count($userIds) > 100) {
            return new WP_Error('too_many_user_ids', 'Maximum of 100 user_ids allowed per request', ['status' => 400]);
        }

        // Batch-fetch expiry configs for all products these users have access history for.
        $allProductIds = $this->orders->fetchDistinctProductIdsForUsers($userIds);
        $expiryConfigs = $this->products->fetchExpiryConfigs($allProductIds);

        $results = [];

        foreach ($userIds as $userId) {
            $user = get_user_by('id', $userId);

            if (! $user) {
                $results[] = ['user_id' => $userId, 'status' => 'not_found'];
                continue;
            }

            $history   = $this->orders->fetchAccessHistoryForUser($userId);
            $expiryMap = $this->products->fetchUserExpiryDatesForUser($userId);
            $events    = $this->service->transformAccessHistoryEvents($history, $expiryConfigs, $expiryMap, false);
            $accessData = $this->service->evaluateCurrentAccesses($userId, $expiryConfigs, $expiryMap);

            $results[] = [
                'user_id'                 => $userId,
                'status'                  => 'found',
                'email'                   => $user->user_email,
                'roles'                   => array_values($user->roles),
                'access_count'            => count($accessData['accesses']),
                'accesses'                => $accessData['accesses'],
                'outdated_accesses_count' => count($accessData['outdated_accesses']),
                'outdated_accesses'       => $accessData['outdated_accesses'],
                'event_count'             => count($events),
                'events'                  => $events,
            ];
        }

        return $results;
    }

    public function byTime(WP_REST_Request $request): WP_Error|array
    {
        $params       = $request->get_json_params();
        $parsedParams = ParamParser::parseSinceAndUntil($params);

        if ($parsedParams instanceof WP_Error) {
            return $parsedParams;
        }

        [$since, $until] = $parsedParams;

        $includeRevocations = $params['include_revocations'] ?? true;
        $newOrders          = $this->orders->fetchOrdersInTimeRange($since, $until);
        $revokedOrders      = $includeRevocations ? $this->orders->fetchRevokedOrders() : [];

        $productIds    = array_values(array_unique(array_column($newOrders, 'product_id')));
        $expiryConfigs = $this->products->fetchExpiryConfigs($productIds);

        $userIds        = array_values(array_unique(array_column($newOrders, 'user_id')));
        $userProductMap = $this->products->fetchUserExpiryDates($userIds);

        $productNames   = $this->products->fetchTermNames($productIds);
        $productCourses = $this->service->getProductCoursesMap($productIds);

        $newGrants = [];

        foreach ($newOrders as $order) {
            $orderId        = (int) $order['order_id'];
            $userId         = (int) $order['user_id'];
            $orderCreatedAt = $order['order_created_at'];
            $productId      = (int) $order['product_id'];

            $productName = $productNames[$productId] ?? null;
            $courses     = $productCourses[$productId] ?? [];

            $resolved     = $this->service->resolveAccessExpiry(
                $productId,
                $expiryConfigs,
                $userProductMap[$userId . '_' . $productId] ?? null,
                $userId,
            );

            $expiresAt    = $resolved['expires_at'];
            $accessStatus = ($expiresAt !== null && $expiresAt < current_time('mysql')) ? 'expired' : 'active';

            foreach ($courses as $course) {
                $newGrants[] = [
                    'user_id'          => $userId,
                    'order_id'         => $orderId,
                    'order_created_at' => $orderCreatedAt,
                    'product_id'       => $productId,
                    'product_name'     => $productName,
                    'course_id'        => $course['course_id'],
                    'course_name'      => $course['course_name'],
                    'status'           => $accessStatus,
                    'expires_at'       => $expiresAt,
                    'expiry_details'   => $resolved['expiry_details'],
                ];
            }
        }

        $totalRevocations = array_map(
            fn($o) => ['id' => (int) $o['order_id'], 'created_at' => $o['created_at']],
            $revokedOrders
        );

        $response = [
            'since'            => $since,
            'until'            => $until,
            'new_grants_count' => count($newGrants),
            'new_grants'       => $newGrants,
        ];

        if ($includeRevocations) {
            $response['total_revocations_count'] = count($totalRevocations);
            $response['total_revocations']        = $totalRevocations;
        }

        return $response;
    }
}
