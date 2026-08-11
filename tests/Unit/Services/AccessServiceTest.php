<?php

declare(strict_types=1);

use ThriveApi\Repositories\OrderRepository;
use ThriveApi\Repositories\ProductRepository;
use ThriveApi\Services\AccessService;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    $this->orders   = Mockery::mock(OrderRepository::class);
    $this->products = Mockery::mock(ProductRepository::class);
    $this->service  = new AccessService($this->orders, $this->products);

    // Stub maybe_unserialize without triggering PHP notices on non-serialised strings.
    Functions\stubs([
        'maybe_unserialize' => function (mixed $v): mixed {
            if (is_string($v) && preg_match('/^[aObidNsCr]:[0-9]/i', $v)) {
                $unserialized = unserialize($v);
                return $unserialized !== false ? $unserialized : $v;
            }
            return $v;
        },
    ]);
});

// ── resolveAccessExpiry ──────────────────────────────────────────────────────

test('resolveAccessExpiry returns null expires_at for unlimited mode', function (): void {
    $configs = [1 => serialize(['enabled' => 0, 'expiry' => []])];

    $result = $this->service->resolveAccessExpiry(1, $configs, null);

    expect($result['expires_at'])->toBeNull();
    expect($result['expiry_details']['mode'])->toBe('unlimited');
    expect($result['validation_error'])->toBeNull();
});

test('resolveAccessExpiry returns the stored date for specific_time mode', function (): void {
    $configs = [1 => serialize(['enabled' => 1, 'expiry' => ['cond' => 'specific_time', 'cond_datetime' => '2026-12-31 23:59:59']])];

    $result = $this->service->resolveAccessExpiry(1, $configs, null);

    expect($result['expires_at'])->toBe('2026-12-31 23:59:59');
    expect($result['expiry_details']['mode'])->toBe('specific_time');
    expect($result['validation_error'])->toBeNull();
});

test('resolveAccessExpiry returns the usermeta value for after_purchase mode', function (): void {
    $configs = [1 => serialize([
        'enabled' => 1,
        'expiry'  => ['cond' => 'after_purchase', 'cond_purchase' => ['number' => 30, 'unit' => 'days']],
    ])];

    $result = $this->service->resolveAccessExpiry(1, $configs, '2026-07-30 00:00:00', 42);

    expect($result['expires_at'])->toBe('2026-07-30 00:00:00');
    expect($result['expiry_details']['mode'])->toBe('after_purchase');
    expect($result['validation_error'])->toBeNull();
});

test('resolveAccessExpiry sets a validation_error when after_purchase usermeta is missing', function (): void {
    $configs = [1 => serialize([
        'enabled' => 1,
        'expiry'  => ['cond' => 'after_purchase', 'cond_purchase' => ['number' => 30, 'unit' => 'days']],
    ])];

    $result = $this->service->resolveAccessExpiry(1, $configs, null, 42);

    expect($result['expires_at'])->toBeNull();
    expect($result['validation_error'])->toContain('missing');
});

test('resolveAccessExpiry returns null expires_at for not_configured mode', function (): void {
    $result = $this->service->resolveAccessExpiry(1, [], null);

    expect($result['expires_at'])->toBeNull();
    expect($result['expiry_details']['mode'])->toBe('not_configured');
    expect($result['validation_error'])->toBeNull();
});

// ── evaluateCurrentAccesses ──────────────────────────────────────────────────

/**
 * Helper: build a serialised unlimited expiry config.
 */
function unlimitedConfig(): string
{
    return serialize(['enabled' => 0, 'expiry' => []]);
}

/**
 * Helper: build a serialised after_purchase expiry config.
 */
function afterPurchaseConfig(): string
{
    return serialize([
        'enabled' => 1,
        'expiry'  => ['cond' => 'after_purchase', 'cond_purchase' => ['number' => 30, 'unit' => 'days']],
    ]);
}

/**
 * Helper: single-course content set for a product.
 */
function contentSetFor(int $productId, int $courseId): array
{
    return [
        'product_id'   => $productId,
        'post_content' => serialize([
            ['content_type' => 'term', 'content' => 'tva_courses', 'value' => [$courseId]],
        ]),
    ];
}

test('evaluateCurrentAccesses places active order in accesses', function (): void {
    Functions\expect('current_time')->with('mysql')->andReturn('2026-06-24 12:00:00');

    $this->orders->allows('findOrderItemsForUser')->with(42)->andReturn([
        ['order_id' => 1, 'order_created_at' => '2026-01-01', 'product_id' => 10, 'order_status' => 1, 'item_status' => 1],
    ]);
    $this->products->allows('fetchTermNames')->andReturn([10 => 'Product X', 100 => 'Course A']);
    $this->products->allows('fetchProductContentSets')->andReturn([contentSetFor(10, 100)]);

    $configs = [10 => unlimitedConfig()];
    $result  = $this->service->evaluateCurrentAccesses(42, $configs, []);

    expect($result['accesses'])->toHaveCount(1);
    expect($result['accesses'][0]['status'])->toBe('active');
    expect($result['outdated_accesses'])->toHaveCount(0);
});

test('evaluateCurrentAccesses places revoked order in outdated_accesses', function (): void {
    $this->orders->allows('findOrderItemsForUser')->with(42)->andReturn([
        ['order_id' => 2, 'order_created_at' => '2026-01-01', 'product_id' => 20, 'order_status' => 4, 'item_status' => 0],
    ]);
    $this->products->allows('fetchTermNames')->andReturn([20 => 'Product Y', 200 => 'Course B']);
    $this->products->allows('fetchProductContentSets')->andReturn([contentSetFor(20, 200)]);

    $configs = [20 => unlimitedConfig()];
    $result  = $this->service->evaluateCurrentAccesses(42, $configs, []);

    expect($result['accesses'])->toHaveCount(0);
    expect($result['outdated_accesses'])->toHaveCount(1);
    expect($result['outdated_accesses'][0]['status'])->toBe('revoked');
});

test('evaluateCurrentAccesses marks an active order with a past expiry as expired', function (): void {
    Functions\expect('current_time')->with('mysql')->andReturn('2026-06-24 12:00:00');

    $this->orders->allows('findOrderItemsForUser')->with(42)->andReturn([
        ['order_id' => 3, 'order_created_at' => '2026-01-01', 'product_id' => 30, 'order_status' => 1, 'item_status' => 1],
    ]);
    $this->products->allows('fetchTermNames')->andReturn([30 => 'Product Z', 300 => 'Course C']);
    $this->products->allows('fetchProductContentSets')->andReturn([contentSetFor(30, 300)]);

    $configs   = [30 => afterPurchaseConfig()];
    $expiryMap = [30 => '2026-01-31 23:59:59']; // already past current_time mock

    $result = $this->service->evaluateCurrentAccesses(42, $configs, $expiryMap);

    expect($result['accesses'])->toHaveCount(0);
    expect($result['outdated_accesses'])->toHaveCount(1);
    expect($result['outdated_accesses'][0]['status'])->toBe('expired');
});

test('evaluateCurrentAccesses marks an active order with a future expiry as active', function (): void {
    Functions\expect('current_time')->with('mysql')->andReturn('2026-06-24 12:00:00');

    $this->orders->allows('findOrderItemsForUser')->with(42)->andReturn([
        ['order_id' => 4, 'order_created_at' => '2026-01-01', 'product_id' => 40, 'order_status' => 1, 'item_status' => 1],
    ]);
    $this->products->allows('fetchTermNames')->andReturn([40 => 'Product W', 400 => 'Course D']);
    $this->products->allows('fetchProductContentSets')->andReturn([contentSetFor(40, 400)]);

    $configs   = [40 => afterPurchaseConfig()];
    $expiryMap = [40 => '2027-01-01 00:00:00']; // well in the future

    $result = $this->service->evaluateCurrentAccesses(42, $configs, $expiryMap);

    expect($result['accesses'])->toHaveCount(1);
    expect($result['accesses'][0]['status'])->toBe('active');
    expect($result['accesses'][0]['expires_at'])->toBe('2027-01-01 00:00:00');
});

test('evaluateCurrentAccesses handles a user with no orders', function (): void {
    $this->orders->allows('findOrderItemsForUser')->with(42)->andReturn([]);
    $this->products->allows('fetchTermNames')->andReturn([]);
    $this->products->allows('fetchProductContentSets')->andReturn([]);

    $result = $this->service->evaluateCurrentAccesses(42, [], []);

    expect($result['accesses'])->toBe([]);
    expect($result['outdated_accesses'])->toBe([]);
    expect($result['outdated_accesses_count'])->toBe(0);
});
