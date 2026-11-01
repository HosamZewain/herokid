# Payment receipt polling handoff — 1 November 2026

Status: inspected/prepared only. No application deployment, payment write, provider mutation, new message or generation in this investigation. Production code verified ae52dcbdf50a56f4ec3c454fa3f065df4e0f1e26.

## Observed owner trial

HK11-12/order2729, identity678 approved, business status costomer_confirmed, run2 waiting_staff/review. On 1 November at11:57:40 UTC an inbound image arrived. At11:57:53.236 UTC an outbound senderType=ai acknowledgment arrived; its replyTo exactly matches that image's channelId. Both are in the preview interaction. This proves a linked acknowledgment, not an actual transfer or a persisted payment proof. Owner explicitly says these are old receipts used for testing.

Read-only production check: payment_status=unpaid, paid_amount_cents=0, order_payment_proofs count for this checkout=0. No financial mutation is authorized by these images. An earlier pair of images at11:05 had no corresponding AI acknowledgment at the earlier audit; the new acknowledgment must not be retroactively attributed to them.

## Missing connection

Current messagesByPhone response contains id, interactionId, direction, type/contentType, text, status, senderType, agentName, fileName, attachments, date, channelId, replyTo, templateName and delivery. Neither the inbound image nor its acknowledgment exposes structured receipt classification. Matching the AI wording would create a dependency on mutable natural-language responses.

HeroKid ProcessResponsePoller handles waiting_response runs only. After identity approval run2 entered waiting_staff and is no longer included in process polling scheduling/full-window eligibility. Ordinary conversation refresh is not a receipt ingestion workflow. Existing PaymentProofService stores pending private proofs through the legacy upload/webhook route; the agreed RoboDesk integration does not call that route.

IMPORTANT: existing legacy approveProof marks PAID_IN_FULL with no explicit actual amount and then sets approved_for_print/PRINTING_READY. Do not reuse this handler for the new deposit/identity workflow. It is inconsistent with partial payment, staff verification and the owner's production boundary. No live handler or record was changed during this audit.

## Request for RoboDesk agent

Use general product fields and existing GET messagesByPhone; no HeroKid-specific endpoint, callback, HMAC or order mutation.

Please confirm whether an existing structured classification identifies an inbound attachment as a payment receipt. If absent, propose an additive general field on the original inbound message, e.g. classification.type=payment_receipt (null/unknown otherwise), with source and classification time. This is a proposal, not a field HeroKid assumes is deployed. Preserve original message id/channelId, interactionId, attachments and all existing fields. Classification identifies a document submitted for review; it never means the money was paid or the document is authentic.

Confirm classification can arrive after initial message ingestion and remains visible on full reads without after; edits must be detectable on reread. Supply a redacted response example and production deployment evidence. An acknowledgment should stay linked by replyTo to the actual attachment. Manual handover/AI pause must not silently imply classification. Do not replay or send customer messages to test this; use synthetic fixtures.

## HeroKid implementation contract pending that answer

- Add a separate payment-review state: awaiting evidence, pending review, approved/rejected. Display pending review on the order without overwriting ledger-derived settlement state or identity state.
- Poll awaiting-evidence/review runs with a bounded full-history schedule, independently of completed identity responses; deduplicate original account/message/attachment IDs. Stop or bound observation explicitly; never enable all historical runs.
- Require inbound attachment classification and deterministic linkage to the checkout using the stored preview interaction/template and phone/account. Multiple eligible checkouts, missing template evidence, unknown classification or context drift go to manual association; never guess from phone alone or an amount.
- Preserve original receipt privately with checksum and immutable provenance using existing media safety checks. Never fetch arbitrary provider URLs with application credentials or expose signed URLs in logs. No duplicate storage on reread. Later classification must be processed even when message content is unchanged.
- Show source message, received time, evidence and review history on the order with existing financial/media permissions. Keep test evidence explicitly marked; old owner receipt is never an actual payment.
- Authorized reviewer must enter/verify actual received amount and method, or link an existing canonical ledger payment. Approval is atomic/idempotent, supports deposits, records reviewer/time/reason, and does not automatically trigger generation, printing or shipping. Rejection records a reason and leaves ledger intact.
- Tests: delayed classification; duplicate/parallel reads; partial payment; linked existing payment; two checkouts per phone; unrelated image or AI text; quoted unrelated receipt; owner test fixture; permission failures; unsafe media; no automatic financial/production side effects.

Implementation/deployment remains pending the provider contract and reviewable tested HeroKid change. The old receipt has not been uploaded, copied into a payment proof or approved by this audit.

## Provider response and prepared ingestion adapter — 1 November 2026

RoboDesk confirmed per-message classification does not exist yet. Proposed original-inbound-message field is `classification: {type: "payment_receipt", source: "ai", at: "<ISO>"}`, null otherwise. AI marks it while responding; tenant settings allow the type, and RoboDesk strips the marker before sending. This is proposed/not deployed. Human/WhatsApp Business handover may prevent AI classification; null means unknown and needs manual review, never a negative receipt finding.

Prepared HeroKid code preserves only the allowlisted type/source/time in the existing encrypted message metadata. The classification participates in the fingerprint, so later full rereads update the same message ID without incrementing the new-inbound counter. Outbound acknowledgments, plain text, empty attachments, unexpected types/sources and malformed dates remain unknown; no free text, amount, URL or purported verification is retained from the classification object. No ledger, proof, order-status or workflow mutation is introduced by this adapter.

Validation: 40 tests/333 assertions passed on isolated in-memory SQLite, external HTTP mocked and container network disabled, PHP8.5 runtime; changed application files passed PHP8.3 syntax checks. Includes delayed metadata, repeated full rereads/deduplication, encrypted persistence, null reversion, unchanged order data, no payment proof creation and malformed/unrelated metadata. No MySQL-specific query introduced. No production test, deployment, polling expansion, permission/configuration change or live receipt import performed.

Still outstanding: provider deployment example/evidence; deterministic order association, persistent private proof ingestion, payment-review UI/manual association and safe actual-amount approval; bounded polling while waiting for evidence; end-to-end tests and scoped deployment approval. Do not label the full payment-review workflow implemented or enabled based on this adapter.

Requested provider acceptance checks: preserve classification on full reads after handover; delayed attachment and delayed classification; duplicate webhook with stable id/channelId; two consecutive images (classify the correct original); no marker visible to customer; absent AI means unknown; timestamps are timezone-qualified; synthetic tests only. Confirm actual attachment message type (image/document/file) and metadata shape in the redacted example before HeroKid enables ingestion.
