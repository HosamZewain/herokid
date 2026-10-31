# First five live confirmations — read-only reconciliation

Inspected AWS 31 October 2026 at 13:44–13:46 UTC (15:44–15:46 Cairo). Live SHA `adfaffa9841c79a16dd3d37383e2f3209a6736f1`. No customer messages, state edits, retries or contact corrections were performed.

| Checkout | HeroKid evidence | Follow-up |
|---|---|---|
| HK10-616 | One accepted send; order remains `new`. Current-contact history synced 13:34:16UTC. | RoboDesk reports Meta 131026 undeliverable. This is provider-reported delivery evidence, not derived from HeroKid's accepted-send status. Staff should use an approved alternate channel; no automatic resend. |
| HK10-617 | One accepted send; `new`; current-contact history synced 13:40:40UTC. | No matching confirmation in saved history at inspection. |
| HK10-618 | `new_confirmed`; confirmed 12:58:54UTC. Exact-template reply at 12:45:07UTC was consumed by the same event once. | Success verified in the live database. Confirmation detection lag 13m47s. |
| HK10-619 | One accepted send; `new`. Current-contact history synced 13:40:45UTC, but confirmation-specific full-window check is false for that contact. | **Current order phone differs from the immutable send payload phone.** Last order update 12:57:44UTC followed send acceptance 12:54:34UTC. Original send contact has no saved history; full-window eligibility is true for the original contact. Background scanner follows the current order contact. Verify the correct contact with the owner/team before any new send; do not treat a reply on the old contact as approval of the changed order. |
| HK10-620 | One accepted send; `new`; current-contact history synced 13:40:47UTC. | RoboDesk reports not delivered yet. No final delivery result is independently available in the current history contract. |

RoboDesk reported five HTTP 200 send results and two HTTP 200 closes. HeroKid independently shows one send attempt per checkout and saved provider-accepted status. Neither HTTP 200 nor the current history `status` field is proof of WhatsApp delivery/read.

At 13:46:55 UTC: 364 active order rows / 258 distinct valid active contacts, scan size 100, batch size 20, minimum interval 600s. Conversation Redis queue had 0 ready, 2 delayed, 0 reserved; conversation and default workers RUNNING. Legacy database, old automation and obsolete worker programs were intentionally STOPPED; no restart was attempted. The configured interval is a minimum per contact, not a promised ten-minute end-to-end confirmation latency. A full pass across 258 due contacts requires approximately 13 batches before skips/rate delays; this is consistent with the reported ~14-minute reads.

Recommendation: surface contact-changed pending confirmations to staff, and evaluate a bounded priority polling lane for unchanged awaiting-confirmation contacts. The new prepared process engine fingerprints inputs and revalidates waits every ten minutes, but it is not deployed and does not remediate HK10-619 retroactively. Do not silently rebind its receipt to another phone, edit the order status, or resend a template.

Evidence artifacts (sanitized, local): `/tmp/herokid-process-runtime-evidence/production-five-orders-audit.json`, `production-619-diagnosis.json`, `production-polling-capacity.json`, `production-sha-workers.txt`. No phone values, auth material or customer message bodies are included.
