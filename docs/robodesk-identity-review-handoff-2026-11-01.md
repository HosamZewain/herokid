# RoboDesk identity-review response handoff — 1 November 2026

Observed on owner-authorized trial HK10-633: identity template delivered, incoming identity approval correlated and consumed once, canonical identity657 approved and linked. RoboDesk AI nevertheless asked the customer what identity confirmation meant. HeroKid has reached manual payment review with paid balance zero. The screenshot also contains a transfer receipt; that is not verified ledger payment evidence.

## Requested RoboDesk review

Use tenant configuration or general product capabilities, not HeroKid-specific product code. No callback, new endpoint or HMAC arrangement is requested. HeroKid continues polling messagesByPhone and owns order state and the financial ledger.

For template herokid_identity_review_payment, inspect the deployed template button labels/types/payloads, current AI instructions/knowledge and flow routing. Explain the exact cause of the generic clarification response before changing rules.

Recognize inbound trimmed text تأكيد الهوية and طلب تعديل الهوية in context of this template, for both quick replies and typed replies. Use matching replyTo/template channelId; where no relevant replyTo is available, use the latest relevant template in the same interaction before the incoming message. Do not treat an outbound AI reply as a customer decision or guess the order when correlation is ambiguous. Preserve all existing message id/date/channelId/replyTo/templateName/interactionId/delivery fields and history used by HeroKid.

Before a generic LLM response, acknowledge a recognized identity confirmation once: “استلمنا موافقتك على هوية الطفل 💛 هنراجع الدفع قبل استكمال الطلب.” Do not ask what identity means, conflate this with confirmation of the original order, or claim payment/production is complete. For revisions: “استلمنا طلب تعديل الهوية 💛 ابعتلنا التعديلات المطلوبة عشان الفريق يراجعها.” Payment receipt handling stays separate: acknowledge receipt and route to human review only if that routing actually occurs. A screenshot must never automatically mark paid or ready for printing/shipping.

Verify that routing suppresses duplicate/conflicting generic replies, without dropping incoming events from the API or disabling normal chat for unrelated text. Do not close conversations or replay customer messages merely to fix the response rule. No new real customer messages or live tests without an explicitly scoped trial.

## Acceptance evidence

Use synthetic fixtures for: button and typed approval; revision; approval followed immediately by a receipt; receipt without approval; duplicate webhook; unrelated quoted message; two orders for one phone; ambiguous context. Report configuration/code change, deployed vs prepared status, expected response per case and dedup/routing evidence. Keep credentials and customer payloads out of the report.

## HeroKid responsibility

HeroKid repair ae52dcbdf50a56f4ec3c454fa3f065df4e0f1e26 was deployed on 1 November2026 at10:06 UTC after owner approval. Future correlated approvals link the identity and advance to existing status key costomer_confirmed (هوية مؤكده و جاهز للتوليد), record status history/outbox and refresh run context. Payment remains unchanged; no new generation/payment/shipping is dispatched by that action. HK10-633 was approved before this repair and was not retroactively replayed or backfilled. No additional trial was run; the owner requested waiting until RoboDesk repairs its replies. This HeroKid change does not repair RoboDesk's response routing.
