# CafeFlo Connect for WordPress

Independent WordPress-side catalog boundary for the native FloCafe Bridge.

## Catalog architecture

CafeFlo products are stored in the existing WordPress custom post type `products`. The connector synchronizes the website-facing product fields through ACF (`price`, `description`, `available`, `visible`, `product_image`). It never needs a WooCommerce product object.

A built-in taxonomy named `cafeflo_product_category` is registered for the `products` post type when no other category taxonomy is selected. An existing taxonomy already attached to `products` can also be selected in the settings page.

## Product identity

FloCafe IDs remain immutable identity keys. The database mapping is scoped by `source_instance_id` and `flocafe_id`, and every `products` post carries `_cafeflo_product_id` as a local identity marker.

Names and slugs are presentation data. A rename therefore does not break the mapping. The connector can also recover a mapped `products` post by `_cafeflo_product_id` when necessary.

## Bridge compatibility

The current native FloCafe Bridge still reads the historical mapping keys `woo_product_id` and `woo_category_id`. The connector keeps those response keys as compatibility aliases while also exposing explicit `wordpress_product_id` / `wordpress_category_id`. The values are WordPress post/term IDs; no WooCommerce API is used.

The catalog snapshot also follows the Bridge contract with `revision`, `source_instance_id`, `generated_at`, `full_snapshot`, and `currency`.

## Orders

WordPress-side orders are intentionally disabled. The Bridge gets an empty `/orders/pending` queue, and the historical order mutation routes return successful no-op responses. This keeps the current Bridge quiet without recreating a fake order database.

## Settings

Open **Settings → CafeFlo Connect** to manage the Bridge key, site ID, category taxonomy, and Rial-to-Toman conversion.

## Requirements

The connector expects the existing `products` post type and ACF to be active for catalog synchronization. It does not require WooCommerce.
