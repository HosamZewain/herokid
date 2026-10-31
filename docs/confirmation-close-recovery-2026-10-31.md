# Confirmation close recovery and Tags wrapping — 31 October 2026

Owner authorized deploying the wrapping change and continuing the approved one-order pilot. Base AWS SHA `157e8faea212506f48f0fef99eda0f46f3dbf7a8` was verified unchanged. This candidate combines wrapped compact tags with explicit completed closure status and one close-only recovery following a fresh phone-validated history read. A second close failure stops; sendMsg uncertainty never automatically retries. Eight possible 12-second calls stay below job 110s/contact-lock 130s/retry 960s.

Validation before deploy: 42 tests / 444 assertions on each isolated SQLite/MySQL, PHP 8.3 transport lint, previous wrap build unchanged. Synthetic browser showed no horizontal overflow and unchanged 38px chips at desktop/mobile widths.

Pilot recovery must preserve attempt one. Recorded evidence proves the original attempt stopped at close HTTP500 before calling sendMsg, and a read-only follow-up found an open conversation with zero confirmation templates. RoboDesk reports a completed rollout as a likely cause, but lost pod logs mean the exact cause is unproven. Owner-directed recovery applies only to HK10-614 / order2682 / its existing event and approved phone, not general unknown/historical jobs. Private operator runner keeps an exclusive lock, checks exact prior sanitized evidence and production-disabled configuration, snapshots the original event privately, then atomically claims unknown/attempt1 as processing/attempt2. It uses the actual deployed transport and never resets attempts, creates another order or enables global sending. Unknown results remain unrepeatable automatically.

Live deployment and pilot results will be recorded after verification. Global all-customer activation is outside this trial.
