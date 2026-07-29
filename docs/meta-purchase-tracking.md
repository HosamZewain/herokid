# Meta Purchase Tracking

HeroKid sends one `Purchase` event for each completed checkout through both:

1. Meta Pixel in the customer browser.
2. Meta Conversions API from a queued server job.

Both channels use the same `event_name=Purchase` and `event_id`, allowing Meta to deduplicate them. The value comes from the saved checkout total after the order transaction succeeds and always includes:

```text
currency = EGP
value = saved checkout total, including delivery
```

Refreshing the checkout success page does not emit another browser event. The database also enforces one server event per `checkout_group_key`.

## Production configuration

Add the following to production `.env`:

```dotenv
META_PIXEL_ID=1011553001490691
META_CONVERSIONS_API_ENABLED=true
META_CONVERSIONS_API_ACCESS_TOKEN=PASTE_THE_TOKEN_FROM_META_EVENTS_MANAGER
META_GRAPH_API_VERSION=v23.0
META_TEST_EVENT_CODE=
```

Create the access token from the Conversions API setup for the same Pixel/Dataset in Meta Events Manager. Keep it only in `.env`; never commit it.

`META_TEST_EVENT_CODE` is optional. Set it temporarily to the code shown in Events Manager → Test Events, place a test order, verify both Browser and Server events with deduplication, then remove the code and rebuild the configuration cache.

## Privacy and reliability

- Phone/email matching values are normalized and SHA-256 hashed before submission.
- Browser identifiers `_fbp` and `_fbc`, IP, and user agent are included when available.
- Stored server user data is encrypted with the Laravel application key.
- Access tokens, raw phone numbers, and provider response bodies are not written to logs.
- Provider failure never rolls back or blocks a valid HeroKid order.
- Delivery runs asynchronously through the existing queue with retries.

## Operational status

Rows in `meta_conversion_events` use:

- `pending`
- `sending`
- `sent`
- `failed`
- `configuration_missing`
- `disabled`

`sent` means Meta acknowledged at least one event. `configuration_missing` indicates the Pixel ID or server access token was absent when the job ran.
