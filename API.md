# CafeFlo Connect API Contract

Base: `https://YOUR-SITE.example/wp-json/flocafe/v1`
Authentication: `X-CafeFlo-Bridge-Key`, `X-FloCafe-Bridge-Key`, `X-FloCafe-Integration-Key`, or `Authorization: Bearer <secret>`.

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/health` | WordPress + Bridge + source catalog status. |
| POST | `/bridge/heartbeat` | Persist Bridge heartbeat and latest FloCafe store state. |
| GET | `/orders/pending?limit=50` | Paid online orders awaiting transfer. |
| POST | `/orders/{id}/claim` | Atomically claim an order; returns `claim_id`. |
| POST | `/orders/{id}/ack` | Persist FloCafe order ID; current claim ID is required. |
| POST | `/orders/{id}/failed` | Persist retryable/permanent failures; permanent paid failures refund. |
| POST | `/orders/{id}/status` | Mirror meaningful FloCafe states to Woo; paid FloCafe cancellations attempt refund. |
| GET | `/store/status` | Current FloCafe-derived online ordering state and freshness. |
| POST | `/catalog/sync` | Apply a source FloCafe snapshot. |
| GET | `/catalog/changes?after_revision=N` | Read WordPress-local catalog changes. |
| GET | `/catalog/snapshot` | Read current mapped Woo catalog with latest FloCafe source revision. |

## Invariants

- FloCafe IDs are immutable identity keys within the source instance. Source instance ID + FloCafe ID identify catalog mappings; product or category names never identify a mapping.
- Source FloCafe revision is distinct from WordPress-local catalog-change revision.
- `catalog/sync` rejects stale revisions and is idempotent for a revision already applied; it also returns current mappings so a restarted Bridge can recover them.
- Full snapshots deactivate mapped products missing from the snapshot and mark missing mapped categories inactive. Nothing is hard-deleted by catalog sync.
- ACF and Elementor presentation fields are not overwritten.
- WooCommerce inventory quantity is intentionally not synchronized.
- Orders are created in WooCommerce as paid online orders, then exposed through `orders/pending`.
- A paid order cannot be ACKed without its current claim token, unless it was already ACKed (idempotent replay).
- Permanent paid transfer failure attempts a real gateway refund with no item restock; refund failure puts the order on hold for manual review.
- FloCafe cancellation of a paid order attempts a gateway refund with no item restock; refund failure puts the order on hold.
- Checkout is rejected while Bridge/FloCafe heartbeats are stale or FloCafe reports online ordering disabled/closed. The menu may remain visible.


### Order status payload

```json
{
  "flocafe_order_id": "123",
  "flocafe_status": "preparing",
  "status": "preparing"
}
```
`flocafe_status` is canonical. The plugin also accepts historical mapped values such as `waiting-cafe`, `waiting-for-cafe`, `received-cafe` and `received-by-cafe`.

- Native FloCafe Bridge sends `source_instance_id` on catalog sync and `bridge_id` on heartbeat; WordPress persists that identity so reconnects from a different FloCafe installation cannot reuse another installation's mappings.
