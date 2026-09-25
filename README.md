# CafeFlo Connect for WordPress

Secure WordPress/WooCommerce boundary used by the local FloCafe Bridge. The plugin never connects to FloCafe SQLite directly.

## Trust boundaries
- FloCafe is authoritative for product identity, availability and price, and final order totals.
- The Bridge is the only process that crosses the Internet/WordPress ↔ local FloCafe boundary.
- ACF and Elementor remain presentation-layer tools and are not overwritten by catalog sync.

## Bridge API
Base: `/wp-json/flocafe/v1`
`GET /health`, `POST /bridge/heartbeat`, `GET /orders/pending?limit=50`, `POST /orders/{id}/claim`, `POST /orders/{id}/ack`, `POST /orders/{id}/failed`, `POST /orders/{id}/status`, `GET /store/status`, `POST /catalog/sync`, `GET /catalog/changes`, `GET /catalog/snapshot`.

## Catalog behavior
FloCafe IDs are immutable mapping keys. Name/SKU never identify a mapping. Product price follows FloCafe. Woo sale price is cleared. Woo stock management is disabled for mapped products because quantity sync is intentionally out of scope. FloCafe deactivation moves the Woo product to draft/hidden instead of deleting it. Full snapshots hide mapped products missing from the snapshot.

## Order behavior
Paid online orders are claimed atomically. Claim tokens are required for ACK. Retries are safe because FloCafe uses the Woo external order ID as its idempotency key. Non-retryable transfer failures automatically refund paid Woo orders when possible; refunded payments keep the Woo financial status as refunded, while an already-refunded/unpaid transfer failure is cancelled. If the refund cannot be completed the order is put on-hold for manual review.

## Checkout safety
Checkout is allowed only while the Bridge heartbeat and FloCafe store-state heartbeat are both fresh (90 seconds) and FloCafe reports online ordering enabled/open. The menu can remain visible while checkout is disabled.

## Installation
Activate WooCommerce, install this plugin, activate it, open **WooCommerce → CafeFlo Connect**, and copy the site ID/secret into the existing Bridge. The Bridge secret is never displayed after activation.

## Validation
The repository includes PHP syntax linting and a static contract audit under `tests/contract-audit.php`. GitHub Actions runs the checks on PHP 7.4, 8.1 and 8.4.
