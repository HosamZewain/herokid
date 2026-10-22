# Store packages: one purchase, one child, independent production

## Administration

Use **Store products → Add package** (`/admin/pricing/create`), or **Packages** to edit an existing package. Existing `settings.pricing.*` permissions govern viewing, creation, updates and deletion.

Choose the number of stories, all eligible stories or a specific allowed selection, products, variants and quantities. Enter the final package price. The component comparison total, savings amount and percentage are calculated automatically. The backend ignores any supplied comparison price and calculates it from the catalog.

If eligible stories have different prices, advertising uses the lowest eligible story price (never an inflated discount). The cart stores the actual selected-story comparison total. Product sale prices and selected variants participate in both calculations. The public comparison is recalculated from the current catalog; historical order prices are not changed.

## Customer flow

`GET /shop/package/{slug}` presents one child form. Its required fields are the union of the story and product configurations. Photos are uploaded once using the existing private temporary-upload service. A component with a lower photo limit receives the first images up to its configured maximum, in the customer's upload order. The form explains this behavior.

`POST /cart/packages/{slug}` sends `shared_child=1`, `child[...]`, and `stories[][story_id]`. Per-story child overrides are ignored in this mode. The legacy story-per-child request shape remains supported for older forms; new website forms use the shared-child contract. Foreign, expired and malformed uploads are rejected. Product-only packages use product-specific fields and photo limits without imposing story requirements.

The cart has one package entry at its configured price. Existing checkout expansion creates individual story/product production records under **one checkout group and one purchase reference**, not unrelated customer orders. Each product uses the existing independent production components and prompt renderer. Story production remains unchanged. The allocated component amounts add up exactly to the package total, including rounding. Delivery, payments, approval and printing workflows are unchanged.

## Verification and release

- `SharedChildPackagesTest`: mixed package checkout, same child, independent rendered prompts on the actual admin group page, quantity/stock, exact allocation, product-only one-photo packages, foreign upload rejection, required fields, repeated stories/shared ordered photos, cleanup, permissions and server-calculated comparison prices.
- Regression suites: `PurchasablePackagesTest`, `ProductProductionComponentsTest`, `AdminProductProductionPromptTest`, `CartCheckoutTest`, `UnifiedCheckoutStatisticsTest`, `TemporaryPhotoUploadTest`.
- Run on disposable SQLite/MySQL test databases only. No real customer order, provider generation, WhatsApp send or payment is needed.
- No new schema or permissions migration. Deploy the pinned main commit using `scripts/deploy-main.sh`; the normal view cache refresh is required.
- This change updates the website/admin package flow. It does not add a new mobile-app package-purchase contract.
