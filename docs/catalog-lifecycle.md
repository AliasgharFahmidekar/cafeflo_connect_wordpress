# Catalog lifecycle

The plugin receives full catalog snapshots from the native FloCafe WordPress Bridge.

- Product lifecycle state controls available, not website visible.
- Products omitted from a full FloCafe snapshot are treated as deleted and the mapped WooCommerce product is permanently deleted.
- The mapping for a deleted product is removed after the WordPress object is deleted.
- Products without a FloCafe category do not retain WooCommerce's default Uncategorized category.
- FloCafe IDs remain the identity key; names and SKUs are not used for mapping identity.
