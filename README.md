# CafeFlo Connect for WordPress

Secure WordPress/WooCommerce boundary used by the native FloCafe WordPress Bridge. The plugin never connects to FloCafe SQLite directly.

## Trust boundaries
- FloCafe is authoritative for product identity, availability and price, and final order totals.
- The native desktop Bridge runs inside FloCafe; a separate Bridge EXE is not required for normal operation.
- The Bridge is the only process that crosses the Internet/WordPress ↔ local FloCafe boundary.
- ACF and Elementor remain presentation-layer tools, while catalog sync populates the configured product ACF fields: `price`, `description`, `available`, `visible`, and `product_image`. `featured` is intentionally untouched because FloCafe does not currently provide that value.

## Bridge API
Base: `/wp-json/flocafe/v1`
`GET /health`, `POST /bridge/heartbeat`, `GET /orders/pending?limit=50`, `POST /orders/{id}/claim`, `POST /orders/{id}/ack`, `POST /orders/{id}/failed`, `POST /orders/{id}/status`, `GET /store/status`, `POST /catalog/sync`, `GET /catalog/changes`, `GET /catalog/snapshot`.

## Catalog behavior
FloCafe IDs are immutable mapping keys. Name/SKU never identify a mapping. Catalog sync writes the website-facing product data into the configured ACF fields (`price`, `description`, `available`, `visible`, `product_image`). `featured` is preserved because FloCafe does not currently send that value. WooCommerce price is still mirrored because WooCommerce requires it for cart/order calculations; Woo sale price is cleared and stock management is disabled because quantity sync is intentionally out of scope. FloCafe deactivation updates the ACF availability/visibility flags and also keeps the Woo product draft/hidden for compatibility. Full snapshots hide mapped products missing from the snapshot.

## Order behavior
Paid online orders are claimed atomically. Claim tokens are required for ACK. Retries are safe because FloCafe uses the Woo external order ID as its idempotency key. Non-retryable transfer failures automatically refund paid Woo orders when possible; refunded payments keep the Woo financial status as refunded, while an already-refunded/unpaid transfer failure is cancelled. If the refund cannot be completed the order is put on-hold for manual review.

## Checkout safety
Checkout is allowed only while the Bridge heartbeat and FloCafe store-state heartbeat are both fresh (90 seconds) and FloCafe reports online ordering enabled/open. The menu can remain visible while checkout is disabled.

## Installation
Activate WooCommerce and ACF, install this plugin, activate it, create the product ACF fields described above, then open **WooCommerce → CafeFlo Connect** and copy the Bridge API key into FloCafe Settings → WordPress / WooCommerce. The API key is stored by WordPress and is used only by the authenticated Bridge.

## Validation
The repository includes PHP syntax linting and a static contract audit under `tests/contract-audit.php`. GitHub Actions runs the checks on PHP 7.4, 8.1 and 8.4.
