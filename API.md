# CafeFlo Connect API Contract

Base: `https://YOUR-SITE.example/wp-json/flocafe/v1`

Authentication: `X-CafeFlo-Bridge-Key`, `X-FloCafe-Bridge-Key`, `X-FloCafe-Integration-Key`, or `Authorization: Bearer <secret>`.

## Catalog

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/health` | WordPress + Bridge + catalog status. |
| POST | `/bridge/heartbeat` | Persist Bridge identity and current FloCafe store state. |
| POST | `/catalog/sync` | Apply a source FloCafe catalog snapshot to the `products` custom post type and ACF. |
| GET | `/catalog/changes?after_revision=N` | Read WordPress-local catalog changes. |
| GET | `/catalog/snapshot` | Read the current mapped catalog in the format expected by the native Bridge. |

Products are stored as WordPress posts with post type `products`. Website-facing values use the existing ACF contract:

- `price`
- `description`
- `available`
- `visible`
- `product_image`

`featured` is intentionally untouched.

## Immutable identity

The authoritative identity is the mapping table key:

`source_instance_id + entity_type + flocafe_id`

The product name, title, slug, SKU, or category name is never used to identify a product. Every mapped `products` post also keeps `_cafeflo_product_id` as a local identity marker.

The mapping response includes both the new explicit WordPress ID and a compatibility alias:

```json
{
  "flocafe_product_id": "123",
  "wordpress_product_id": 456,
  "woo_product_id": 456
}
```

`woo_product_id` is retained only because the current FloCafe Bridge already reads that wire field. It is the same WordPress post ID and does not imply a runtime WooCommerce dependency.

This means changing a product title/name does not change its identity. If a mapped post is deleted, the next catalog synchronization may create a replacement `products` post and update the same FloCafe-ID mapping to the new WordPress post ID.

## Orders compatibility

WordPress-side orders are intentionally disabled.

`GET /orders/pending` always returns:

```json
{
  "orders": [],
  "orders_enabled": false,
  "reason": "orders_disabled"
}
```

The legacy mutation endpoints remain available:

- `POST /orders/{id}/claim`
- `POST /orders/{id}/ack`
- `POST /orders/{id}/failed`
- `POST /orders/{id}/status`

They return HTTP 200 no-op responses such as:

```json
{
  "ok": true,
  "ignored": true,
  "reason": "orders_disabled"
}
```

This is deliberate. The current FloCafe Bridge continues polling these endpoints, so removing them entirely would create repeated HTTP errors even though there are no WordPress orders to transfer.

## Independence boundary

This plugin does not require WooCommerce, does not check for its presence, and does not register WooCommerce lifecycle hooks. The catalog path uses WordPress core, the `products` custom post type, ACF, and the existing CafeFlo mapping database.
