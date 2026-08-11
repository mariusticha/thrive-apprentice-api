<?php

declare(strict_types=1);

namespace ThriveApi\Controllers;

use WP_Error;
use WP_REST_Request;
use ThriveApi\Repositories\OrderRepository;
use ThriveApi\Repositories\ProductRepository;
use ThriveApi\Services\AccessService;
use ThriveApi\Support\ExpiryParser;
use ThriveApi\Support\ParamParser;

class AccessMutationController
{
    public function __construct(
        private AccessService $service,
        private OrderRepository $orders,
        private ProductRepository $products,
    ) {}

    public function revoke(WP_REST_Request $request): WP_Error|array
    {
        $params = $request->get_json_params();

        $userId = ParamParser::parseUserId($params['user_id'] ?? null);
        if ($userId instanceof WP_Error) return $userId;

        $recordIds = ParamParser::parseRecordIds($params['record_ids'] ?? null);
        if ($recordIds instanceof WP_Error) return $recordIds;

        $resource = $this->parseResource($params['resource'] ?? '');
        if ($resource instanceof WP_Error) return $resource;

        $context = $this->service->buildSemanticContext($userId, $resource, $recordIds, order_status: 1, item_status: 1);
        if ($context instanceof WP_Error) return $context;

        $ordersUpdated = $this->orders->updateOrderStatuses($context['order_ids'], 4);
        $itemsUpdated  = $this->orders->updateItemStatuses($context['item_ids'], 0);

        return ['message' => 'success', 'orders_updated' => $ordersUpdated, 'items_updated' => $itemsUpdated];
    }

    public function restore(WP_REST_Request $request): WP_Error|array
    {
        $params = $request->get_json_params();

        $userId = ParamParser::parseUserId($params['user_id'] ?? null);
        if ($userId instanceof WP_Error) return $userId;

        $recordIds = ParamParser::parseRecordIds($params['record_ids'] ?? null);
        if ($recordIds instanceof WP_Error) return $recordIds;

        $resource = $this->parseResource($params['resource'] ?? '');
        if ($resource instanceof WP_Error) return $resource;

        $context = $this->service->buildSemanticContext($userId, $resource, $recordIds, order_status: 4, item_status: 0);
        if ($context instanceof WP_Error) return $context;

        $ordersUpdated = $this->orders->updateOrderStatuses($context['order_ids'], 1);
        $itemsUpdated  = $this->orders->updateItemStatuses($context['item_ids'], 1);

        return ['message' => 'success', 'orders_updated' => $ordersUpdated, 'items_updated' => $itemsUpdated];
    }

    public function delete(WP_REST_Request $request): WP_Error|array
    {
        $params = $request->get_json_params();

        $userId = ParamParser::parseUserId($params['user_id'] ?? null);
        if ($userId instanceof WP_Error) return $userId;

        $recordIds = ParamParser::parseRecordIds($params['record_ids'] ?? null);
        if ($recordIds instanceof WP_Error) return $recordIds;

        $resource = $this->parseResource($params['resource'] ?? '');
        if ($resource instanceof WP_Error) return $resource;

        $context = $this->service->buildSemanticContext($userId, $resource, $recordIds);
        if ($context instanceof WP_Error) return $context;

        $productIds = $context['product_ids'];

        // Delete items before orders (FK safety).
        $itemsDeleted  = $this->orders->deleteOrderItems($context['item_ids']);
        $ordersDeleted = $this->orders->deleteOrders($context['order_ids']);

        $historyDeleted  = 0;
        $usermetaDeleted = 0;

        if (! empty($productIds)) {
            $historyDeleted  = $this->orders->deleteAccessHistoryForUser($userId, $productIds);
            $usermetaDeleted = $this->products->deleteUserExpiryMeta($userId, $productIds);
        }

        return [
            'message'          => 'success',
            'orders_deleted'   => $ordersDeleted,
            'items_deleted'    => $itemsDeleted,
            'history_deleted'  => $historyDeleted,
            'usermeta_deleted' => $usermetaDeleted,
        ];
    }

    public function update(WP_REST_Request $request): WP_Error|array
    {
        $params = $request->get_json_params();

        $userId = ParamParser::parseUserId($params['user_id'] ?? null);
        if ($userId instanceof WP_Error) return $userId;

        $recordId = ParamParser::parseRecordId($params['record_id'] ?? null);
        if ($recordId instanceof WP_Error) return $recordId;

        $resource = $this->parseResource($params['resource'] ?? '');
        if ($resource instanceof WP_Error) return $resource;

        $expiresAtRaw = $params['expires_at'] ?? '';

        if (empty($expiresAtRaw)) {
            return new WP_Error('invalid_expires_at', "The 'expires_at' parameter is required.", ['status' => 400]);
        }

        $parsed = strtotime($expiresAtRaw);

        if ($parsed === false) {
            return new WP_Error(
                'invalid_expires_at',
                "The 'expires_at' parameter must be a valid date or datetime string.",
                ['status' => 400]
            );
        }

        $expiresAt = date('Y-m-d H:i:s', $parsed);

        $context = $this->service->buildSemanticContext($userId, $resource, [$recordId]);
        if ($context instanceof WP_Error) return $context;

        $productIds    = $context['product_ids'];
        $expiryConfigs = $this->products->fetchExpiryConfigs($productIds);

        // All target products must use after_purchase mode — the only mode that stores
        // a per-user date in usermeta and therefore supports custom overrides.
        foreach ($productIds as $productId) {
            $expiryInfo = ExpiryParser::parseProductExpiry($productId, $expiryConfigs);

            if ($expiryInfo['mode'] !== 'after_purchase') {
                return new WP_Error(
                    'product_expiry_cannot_be_updated',
                    sprintf(
                        "Product %d uses expiry mode '%s'. Only 'after_purchase' products support a custom per-user expiry date.",
                        $productId,
                        $expiryInfo['mode']
                    ),
                    ['status' => 422]
                );
            }
        }

        $hasUpdated = false;
        $hasCreated = false;

        foreach ($productIds as $productId) {
            $result = $this->products->upsertUserExpiryDate($userId, $productId, $expiresAt);

            if ($result === 'updated') $hasUpdated = true;
            if ($result === 'created') $hasCreated = true;
        }

        $info = $hasUpdated ? 'expiry_updated' : ($hasCreated ? 'expiry_created' : 'expiry_unchanged');

        return ['message' => 'success', 'info' => $info];
    }

    private function parseResource(string $resource): WP_Error|string
    {
        if (! in_array($resource, ['product', 'order'], true)) {
            return new WP_Error('invalid_resource', "resource must be 'product' or 'order'", ['status' => 400]);
        }

        return $resource;
    }
}
