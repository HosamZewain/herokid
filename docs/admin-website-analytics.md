# HeroKid Admin Website Analytics

## Architecture

The admin analytics page lives at `/admin/analytics` and combines two clearly separated data sources:

- GA4 Data API: general visitor traffic, realtime active users, pages, devices, locations, campaigns, and landing pages.
- Local HeroKid database: cart visibility, checkout progress, completed orders, local revenue, abandoned carts, and conversion rates.

Data flow:

1. Admin opens `/admin/analytics`.
2. `AnalyticsController` asks `Ga4AnalyticsRepository` for the selected date range.
3. The repository reads cached GA4 reports or calls `Ga4AnalyticsClient`.
4. `Ga4AnalyticsClient` authenticates with a Google service account using a signed JWT and calls the GA4 Data API.
5. Local cart and order metrics are read from `visitor_carts`, `visitor_cart_items`, `visitor_cart_activities`, `orders`, and `order_items`.
6. The Blade view renders Arabic RTL cards, charts, tables, and setup/error states.

Credentials, OAuth access tokens, service-account JSON, private keys, and raw API responses are never sent to the browser.

## Required Environment Variables

```env
GA4_PROPERTY_ID=
GOOGLE_ANALYTICS_CREDENTIALS_PATH=
GOOGLE_ANALYTICS_CREDENTIALS_BASE64=
ANALYTICS_CACHE_TTL=900
ANALYTICS_BREAKDOWN_CACHE_TTL=1800
ANALYTICS_REALTIME_CACHE_TTL=60
ANALYTICS_REQUEST_TIMEOUT=10
CART_ABANDONED_AFTER_MINUTES=360
CART_ACTIVITY_RETENTION_DAYS=60
```

Use either `GOOGLE_ANALYTICS_CREDENTIALS_PATH` or `GOOGLE_ANALYTICS_CREDENTIALS_BASE64`.

Do not put the credentials file inside `public/` or any public web directory.

## Google Cloud Setup

1. Open Google Cloud Console.
2. Create or select a project.
3. Enable `Google Analytics Data API`.
4. Create a Service Account.
5. Create a JSON key for that service account.
6. In GA4 Admin, open the GA4 property.
7. Go to Property Access Management.
8. Add the service account email as Viewer.
9. Copy the numeric GA4 property ID into `GA4_PROPERTY_ID`.

## Hostinger Shared Hosting Setup

Recommended file path:

```bash
mkdir -p /home/u470070883/private
chmod 700 /home/u470070883/private
```

Upload the service account JSON to:

```text
/home/u470070883/private/ga4-service-account.json
```

Set in `.env`:

```env
GA4_PROPERTY_ID=123456789
GOOGLE_ANALYTICS_CREDENTIALS_PATH=/home/u470070883/private/ga4-service-account.json
```

Then run:

```bash
cd /home/u470070883/domains/hero-kid.com/public_html
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

If file upload is difficult, base64 encode the JSON locally and set:

```env
GOOGLE_ANALYTICS_CREDENTIALS_BASE64=...
```

## Cache Behavior

- Realtime active users: 60 seconds.
- Today/yesterday summary: 900 seconds by default.
- Charts and breakdowns: 1800 seconds by default.
- Cache keys include the GA4 property ID and date range.
- The Refresh button clears only registered analytics cache keys for the current property.

## Local Cart Visibility

Visitor cart visibility is first-party only and lives at:

```text
/admin/visitor-carts
```

It stores a secure cart identifier, an HMAC visitor hash, optional customer ID, item snapshots, local totals, local UTM values, conversion status, related order, and a cart activity timeline.

It does not store raw cookies, full session IDs, access tokens, or uploaded child photo payloads.

Guest carts appear as:

```text
زائر غير مسجل
```

If a guest later logs in before checkout, the existing local cart is associated with the authenticated customer. When checkout creates an order, the cart is marked `converted` and linked to the first created order.

## External Ecommerce Tracking

HeroKid keeps the base GA4 page tag and Meta Pixel PageView for general analytics, but cart and ecommerce events are local-only.

The public site does not emit cart/product ecommerce events such as:

- `view_item`
- `add_to_cart`
- `begin_checkout`
- `purchase`
- Meta `ViewContent`
- Meta `AddToCart`
- Meta `Purchase`

The analytics funnel in the admin dashboard is computed from the local HeroKid database.

## Scheduler / Cron

The local cart maintenance command marks inactive carts as abandoned and cleans old activity rows:

```bash
php artisan visitor-carts:maintain
```

It is registered in Laravel's scheduler and should run through the existing Hostinger cron:

```bash
* * * * * /usr/bin/php /home/u470070883/domains/hero-kid.com/public_html/artisan schedule:run >> /home/u470070883/domains/hero-kid.com/public_html/storage/logs/scheduler.log 2>&1
```

If you use a wrapper script for queues/scheduler, keep the scheduler call there. The command is idempotent.

## Troubleshooting

- Setup state means `GA4_PROPERTY_ID` or credentials are missing/invalid.
- API failure state usually means the service account does not have GA4 property access, the Data API is not enabled, or the property ID is wrong.
- Realtime means active users in the last 30 minutes, as reported by GA4 Realtime API.
- Data may take time to appear in historical GA4 reports.
- Local cart stats are available immediately after visitors add to cart or checkout.
- Never paste credentials into admin settings or commit them to Git.

## Test Commands

```bash
php artisan test --filter=AdminAnalyticsTest
php artisan test --filter=Analytics
php artisan test
npm run build
```
