# HeroKid Bosta integration

HeroKid creates and follows Bosta deliveries at the checkout-group level. One checkout group has at most one Bosta delivery, even when it contains several story or product order rows.

## Accounting boundary

Bosta COD is operational shipping information only. It is the remaining amount requested from the recipient and is stored only on the Bosta shipment records.

- Creating a delivery or pickup does not change `paid_amount_cents` or `payment_status`.
- Webhook delivery events do not create `order_payment_events`.
- A Bosta `Delivered` event changes the HeroKid shipping status only.
- Bosta COD does not contribute to sales, Payments Today, expenses, or the current balance.
- Admin payment confirmation and HeroKid's payment ledger remain the only financial source of truth.

## Environment configuration

Add these values to production `.env`:

```dotenv
BOSTA_ENABLED=true
BOSTA_BASE_URL=https://app.bosta.co/api/v2
BOSTA_API_KEY=replace-with-read-write-key
BOSTA_BUSINESS_LOCATION_ID=replace-with-existing-location-id
BOSTA_COUNTRY_ID=60e4482c7cb7d4bc4849c4d5
BOSTA_DEFAULT_PACKAGE_TYPE=Small
BOSTA_ALLOW_OPEN_PACKAGE=false
BOSTA_WEBHOOK_HEADER=X-Bosta-Webhook-Secret
BOSTA_WEBHOOK_SECRET=replace-with-a-long-random-secret
BOSTA_TIMEOUT=30
BOSTA_CONNECT_TIMEOUT=10
BOSTA_RETRIES=2
```

Never commit the API key or webhook secret. After changing `.env`, rebuild Laravel's configuration cache.

## Admin workflow

Administrators with the relevant permissions use the following workflow:

1. Set the shipping status of every row in the checkout group to `ready` (جاهز للشحن) from the order page.
2. Open the Bosta panel on that order, review and optionally edit the receiver, phone, address, and operational COD.
3. Confirm delivery creation. HeroKid sends the reviewed data, the configured business location, a `Small` parcel, and `allowToOpenPackage=false`.
4. After Bosta confirms creation, HeroKid moves the checkout shipping status to `shipment_created` (تم إنشاء شحنة).
5. Open `/admin/bosta`; its pickup table contains only created deliveries that have not been attached to a pickup.
6. Select deliveries and request a pickup manually for a chosen date, or open their generated A4 AWB PDF.
7. Follow the current Bosta state and tracking number from the order page.

The Bosta page may show ready checkouts as shortcuts, but delivery creation always takes place after reviewing the editable data on the order page.

Failed delivery creation is kept as a failed local attempt and can be retried. A unique database constraint and a pending-request guard prevent duplicate local or concurrent delivery creation for the same checkout group.

## Permissions

- `bosta.view`
- `bosta.create_shipment`
- `bosta.create_pickup`
- `bosta.print_awb`

The migration grants these permissions to existing active administrators who already hold the manager-level permission. Other administrators can be configured from the existing permission management screen.

## Webhook

Configure Bosta to send delivery status webhooks to:

```text
https://hero-kid.com/api/integrations/bosta/webhook
```

Configure the same custom authorization header and secret used by `BOSTA_WEBHOOK_HEADER` and `BOSTA_WEBHOOK_SECRET`. Requests with a missing or wrong secret are rejected. Repeated Bosta events are deduplicated before any shipping-state update.

The webhook records provider event details and maps supported Bosta states to HeroKid shipping behaviors. It deliberately does not call the order payment or sales services.

## Deployment checks

```bash
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

Then verify `/admin/bosta`, create one real low-risk delivery, confirm it in Bosta, and send a signed webhook test. Confirm that the order's shipping status changes while its paid amount, payment status, payment ledger, and sales reports remain unchanged.
