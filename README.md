# Thrive Apprentice API

WordPress plugin exposing Thrive Apprentice access data and write operations over REST.

## Write Endpoints

The following endpoints mutate access state:

- PATCH `/apprentice/v1/accesses/update`
- PATCH `/apprentice/v1/accesses/revoke`
- PATCH `/apprentice/v1/accesses/restore`
- DELETE `/apprentice/v1/accesses/delete`

All write endpoints share the same semantic validation layer for:

- user existence (`user_not_found`, HTTP 404)
- product existence (`product_not_found`, HTTP 404)
- matching order-item records (`no_orders_found`, HTTP 422)
- ambiguous order targeting for `resource=order` (`ambiguous_order`, HTTP 422)

Input-format validation errors stay endpoint-local and return HTTP 400.

## `/accesses/update` Upsert Behavior

Endpoint: PATCH `/apprentice/v1/accesses/update`

Request body:

```json
{
	"user_id": 123,
	"record_id": 456,
	"resource": "product",
	"expires_at": "2026-12-31 23:59:59"
}
```

Notes:

- `record_id` is interpreted as `product_id` when `resource=product`.
- `record_id` is interpreted as `order_id` when `resource=order`.
- `expires_at` is normalized to `Y-m-d H:i:s`.
- Revoked records are eligible for upsert (create/update/unchanged).

Success response shape:

```json
{
	"message": "success",
	"info": "expiry_updated"
}
```

Possible `info` values:

- `expiry_updated`: at least one existing expiry row changed.
- `expiry_created`: no rows changed, at least one missing row was created.
- `expiry_unchanged`: all matching rows already had the requested value.

For `resource=order` that resolves to multiple products, outcome precedence is:

1. `expiry_updated`
2. `expiry_created`
3. `expiry_unchanged`

## Error Codes (Logical)

Shared logical error codes across write endpoints:

- `user_not_found` (404)
- `product_not_found` (404)
- `no_orders_found` (422)
- `ambiguous_order` (422)

Write failures in persistence return:

- `db_write_failed` (500)
