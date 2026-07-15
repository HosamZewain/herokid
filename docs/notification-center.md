# HeroKid Admin Notification Center

The Admin Notification Center sends operational alerts to configured admin recipients when important order, Production Studio, and AI generation events happen. Telegram is the first implemented channel. The architecture is channel-based so email, WhatsApp, Slack, webhooks, and database admin notifications can be added later without changing domain flows.

## Telegram Setup

1. In Telegram, open `@BotFather`.
2. Send `/newbot` and follow the prompts.
3. Copy the bot token. Treat it like a password.
4. Send a message to the bot from the target admin account or add the bot to the target group.
5. Get the chat ID:
   - Use a temporary bot update inspection tool, or
   - Call `https://api.telegram.org/bot<token>/getUpdates` after sending a message to the bot.
6. Open Admin > Settings > Notification Center.
7. Save the Telegram bot token and default Chat ID.
8. Enable Telegram and send a test message.

The bot token is encrypted in the database. It is never rendered back in Admin after save; only a masked `••••••••abcd` status is shown.

## Event Rules

Rules are managed per event and channel. Telegram defaults are:

Enabled by default:

- `order.created`
- `production.project.created`
- `production.project.completed`
- `production.project.stuck`
- `production.project.budget_exceeded`
- `ai.generation.failed`
- `ai.generation.stuck`
- `ai.generation.budget_exceeded`

Disabled by default:

- `production.project.started`
- `ai.generation.completed`

Messages include concise operational data and admin links only. They do not include child image URLs, signed media URLs, prompts, provider credentials, or raw sensitive provider payloads.

## Legacy Env Import

Admin-managed encrypted credentials take precedence. `.env` values are optional and only useful for legacy import:

```bash
php artisan notifications:import-telegram --yes
```

Use `--force` only when intentionally replacing the encrypted Admin-managed token with the legacy `.env` token.

## Stuck Detection

The scheduled command checks:

- Active Production Studio projects with no recent update/activity.
- AI generation jobs in `queued` or `processing` too long.
- Repeat alert throttling so stuck checks do not spam Telegram.

Run manually:

```bash
php artisan notifications:check-stuck-production
```

Suggested Hostinger cron every 10 minutes:

```cron
*/10 * * * * cd /home/u470070883/domains/hero-kid.com/public_html && php artisan notifications:check-stuck-production >> storage/logs/notifications.log 2>&1
```

If Hostinger requires an explicit PHP binary, use the deployed PHP path, for example:

```cron
*/10 * * * * cd /home/u470070883/domains/hero-kid.com/public_html && /opt/alt/php84/usr/bin/php artisan notifications:check-stuck-production >> storage/logs/notifications.log 2>&1
```

## Budget Settings

Admin settings:

- `production_stuck_after_minutes`, default `120`
- `ai_job_stuck_after_minutes`, default `20`
- `repeat_stuck_alert_after_minutes`, default `180`
- `production_default_ai_budget_usd`, default `2.00`
- `ai_job_warning_cost_usd`, default `0.20`
- `ai_project_warning_cost_usd`, default `2.00`
- `notify_on_budget_80_percent`, default `true`

Project budget notifications are deduped. The 80% warning and exceeded alert are each sent once per project unless future per-project budget logic changes the dedupe key.

## Queue Requirements

Notification sends are queued with `SendNotificationJob`. Telegram downtime does not block checkout, order creation, Production Studio updates, or AI generation jobs. Delivery success/failure is recorded in `notification_deliveries`.

Hostinger must keep the Laravel queue worker/cron flow running as already required by Production Studio jobs.

## Security Rules

- Telegram bot token is encrypted at rest.
- Token input is never prefilled.
- Blank token input keeps the existing token.
- Replacing a token requires confirmation.
- Removing a token disables Telegram until a new token is saved.
- Test messages are sent server-side only.
- Delivery logs store safe response metadata only.
- Messages never include private child image paths, signed media links, prompts, API keys, or raw provider payloads.
- Token save/remove/test actions are rate-limited and CSRF-protected.

## Future Channels

To add a channel:

1. Add a channel definition in `config/admin_notifications.php`.
2. Implement `App\Contracts\NotificationChannelInterface`.
3. Register rules for the channel type.
4. Extend `SendNotificationJob` channel resolution.
5. Add Admin settings UI for channel-specific configuration.

Domain code should continue dispatching event keys through `AdminNotificationDispatcher`; it should not call channel APIs directly.

## Troubleshooting

Messages not arriving:

- Confirm Telegram channel is enabled.
- Confirm a bot token is configured.
- Confirm default Chat ID is correct.
- Send a test message from Admin.
- Check `notification_deliveries` for failed rows.
- Confirm the queue worker or Hostinger cron is running.

Invalid token:

- Replace the token from BotFather.
- Confirm replacement in Admin.
- Send a test message.

Wrong Chat ID:

- Message the bot or add it to the group.
- Re-check the chat ID from bot updates.
- Save the corrected Chat ID in Admin.

Stuck alerts not arriving:

- Confirm the stuck command is scheduled.
- Run `php artisan notifications:check-stuck-production` manually.
- Check `notification_last_stuck_check_run_at` in Admin overview.
- Confirm the relevant event rules are enabled.
