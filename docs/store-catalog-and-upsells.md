# Hero Kid Store Catalog and Upsells

## Architecture

The store is built as a generic catalog, separate from the existing personalized Story catalog.

- `product_categories`: dynamic admin-managed store categories.
- `products`: generic physical/digital products with price snapshots, behavior flags, SEO fields, inventory settings, age groups, and media.
- `product_variants`: optional product choices such as size, pack count, material, or format.
- `homepage_store_sections`: admin-managed homepage sections tied to product categories.
- `product_upsell_rules`: admin-managed recommendation rules for story-driven upsells.
- `order_items`: immutable snapshots for stories, products, variants, and personalized add-ons inside each order.

Existing personalized stories remain in the `stories` table and keep their current public URLs and production flow.

## Product Behavior Flags

Products use flexible behavior fields:

- `fulfillment_type`: `physical` or `digital`.
- `purchase_mode`: `standalone`, `add_on_only`, or `standalone_or_add_on`.
- `personalization_mode`: `none`, `inherit_from_linked_story`, or `collect_child_details`.
- `inventory_mode`: `no_tracking`, `track_stock`, or `made_to_order`.

Phase 1 fully supports:

- Regular products with `personalization_mode = none`.
- Personalized add-ons with `personalization_mode = inherit_from_linked_story`.

`collect_child_details` is modeled for future product flows but does not add a separate child photo upload flow yet.

## Category Setup

The migration creates these initial categories:

- `activities-learning`: كتب أنشطة وتعلّم
- `ready-stories`: قصص جاهزة للقراءة
- `personalized-gifts`: هدايا مخصصة

They are not enough to show public products. A category appears publicly only when it is active, visible in store, and has at least one active product.

## Creating a Product

In admin:

1. Open `Admin > المتجر`.
2. Create or edit a product category.
3. Create a product and choose category, price, media, age groups, inventory mode, and behavior flags.
4. Enable `نشط` when ready to publish.
5. Add variants from the product edit page if needed.

For all-ages products, leave age groups empty.

## Creating a Personalized Add-On

Use these product settings:

- `fulfillment_type = physical`
- `purchase_mode = add_on_only` or `standalone_or_add_on`
- `personalization_mode = inherit_from_linked_story`

When added from a cart with one personalized story, the add-on links to that story automatically. When multiple personalized stories exist, the parent must choose the child/story. The add-on reuses the story cart item’s child name, age, gender, story, consent context, and uploaded photo references without asking for another upload.

## Upsell Rules

Admin rules can recommend a target product based on:

- all stories
- specific story
- story category
- age group
- gender
- trigger scope
- priority

More specific and higher-priority active rules are shown first. Inactive products and hidden categories are not shown publicly.

## Mixed Carts

The session cart now supports:

- `story`: existing personalized story item.
- `product`: regular store product item.
- `product_add_on`: product linked to a story cart item.

Removing a personalized story also removes linked add-ons so orphaned personalized gifts cannot remain in the cart.

## Checkout and Orders

Checkout still creates one order per personalized story for backward compatibility. Product snapshots are saved in `order_items`.

- Story-only orders keep existing behavior.
- Mixed story/product orders attach linked add-ons to the correct story order item.
- Standalone products in a mixed cart attach to the first checkout order.
- Product-only carts create one order with nullable story/child fields and product `order_items`.

Shipping is still charged once per checkout group. Split shipments are not implemented in Phase 1.

## Production Identification

Admin order details show order items and linked add-ons. For personalized add-ons, the `linked_order_item_id` points to the exact story order item, and `personalization_snapshot` contains the child/story context used at checkout.

Child photo files remain private and are not exposed through public product URLs.

## Inventory

- `no_tracking`: always purchasable while active.
- `made_to_order`: purchasable while active.
- `track_stock`: cart and checkout prevent quantities beyond available stock.

Variant stock is used when a variant has its own stock value; otherwise the product stock is used.

## Test Commands

```bash
docker compose exec -T laravel.test php artisan migrate
docker compose exec -T laravel.test php artisan test tests/Feature/StoreCatalogTest.php
docker compose exec -T laravel.test php artisan test tests/Feature/CartCheckoutTest.php
docker compose exec -T laravel.test php artisan test
npm run build
```

