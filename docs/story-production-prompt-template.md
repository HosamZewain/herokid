# Story Production Prompt Template

## What It Is

The Story Production Prompt Template is a global admin-managed template used to generate the Story Production Prompt on each admin order details page.

The template contains shared production instructions plus safe variables such as `{{child_name}}` and `{{story_title}}`. When an admin opens an order, Hero Kid replaces supported variables with real order data and renders the final prompt in the existing editable textarea.

Templates are plain text only. They cannot execute Blade, PHP, JavaScript, or any other code.

## Admin Page

Open:

`/admin/settings/story-production-prompt`

Only authenticated admin users can access it.

The page includes:

- Large monospace template editor.
- Variable reference panel.
- Insert buttons for variables.
- Save button.
- Existing-order preview workflow.
- Copy preview button.
- Restore default template button.
- Last updated timestamp and editor when available.

## Supported Variables

```text
{{order_number}}
{{order_url}}
{{child_name}}
{{child_age}}
{{child_gender}}
{{child_interests}}
{{child_image_references}}
{{story_title}}
{{story_age_range}}
{{story_short_description}}
{{story_full_content}}
{{story_educational_value}}
{{dedication}}
{{customer_notes}}
{{production_language}}
{{current_date}}
```

Missing values render as:

```text
Not available
```

If no child images exist, `{{child_image_references}}` renders:

```text
No child images were attached to this order.
```

Child image references are signed production URLs, not private local file paths.

## Editing Instructions

Add, remove, or rewrite any production instructions directly in the template editor.

Use variables wherever order-specific values should be inserted. For example:

```text
- Child name: {{child_name}}
- Selected story: {{story_title}}
- Child images:
{{child_image_references}}
```

Unknown variables are rejected before saving. For example, `{{unknown_variable}}` will show a validation error.

## Preview

Use the preview section to select an existing order and render the current editor content against that order. Previewing does not save the template.

## Per-Order Overrides

On each admin order page:

- By default, the order uses the global template.
- `Save as Order-Specific Prompt` stores the current textarea text for that order only.
- Orders with overrides are not changed by future global template edits.
- `Reset to Global Template` removes the override and rebuilds from the active global template.

The order page shows either:

- `Using Global Template`
- `Using Order-Specific Override`

## Snapshots

Admins can click `حفظ نسخة إنتاج` / `Save Production Snapshot` on the order page to save the exact current textarea content.

A snapshot is also saved automatically once when the order first moves into a production status such as `generating`, `approved_for_print`, or `printing`.

Snapshots are immutable records and are not overwritten by later template changes.

## Restore Default Template

The default template is source-controlled in:

`app/Support/DefaultStoryProductionPromptTemplate.php`

Click `استعادة القالب الافتراضي` on the settings page to restore it. This does not delete per-order overrides or snapshots.

## Security

- Admin-only routes.
- Strict allow-list of variables.
- No template code execution.
- No public access to production prompts or child image URLs.
- Child images remain behind signed/admin-only production routes.

## Test Commands

```bash
docker compose exec -T laravel.test php artisan migrate
docker compose exec -T laravel.test php artisan test tests/Feature/AdminOrderProductionPromptTest.php
docker compose exec -T laravel.test php artisan test
npm run build
```

