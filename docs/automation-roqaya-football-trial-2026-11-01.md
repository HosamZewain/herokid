# Roqaya football owner trial — 1 November 2026

## Authorization and identity

Owner explicitly requested a new real end-to-end identity trial after RoboDesk's repair, using the two supplied Roqaya photos and a football story. Owner confirmed age7. Existing owner-approved WhatsApp recipient retained; no new customer audience. One identity generation and the two confirmation/preview stages are in scope. Stop at manual payment review; no fabricated payment, printing, shipment or scene generation. Uncertain outcomes must not auto-retry.

Order **HK11-12 / 2729**, child **رقية**, girl,7, Arabic; active story57 **بطلة كرة القدم: مباراة من أجل الفريق**. Created through canonical AdminOrderCreationService at approximately10:47 UTC, marked OWNER-ROQAYA-FOOTBALL-TRIAL-20261101 with explicit no-print/no-ship delivery and parent notes. Current pricing service produced399EGP item +90 delivery =489, paid0; no manual pricing override. Source filenames IMG_4938.jpg and IMG_4686 2.jpg, bytes3,918,680 and3,660,388. Exact originals uploaded via standard private order-photo storage; both stored SHA256 values matched the originals. No child photos, signed URLs or private recipient data committed to Git.

## Verified RoboDesk repair

Authenticated browser read confirmed hero-kid.general **version16**, production/latest, timestamp1November12:42:10 Cairo. Its instructions explicitly recognize confirmation and revision texts with the expected acknowledgments and separate receipt review from verified payment. RoboDesk reports12 synthetic AI checks and unchanged API fields; that report is not proof of this new real trial. The tenant UI was accessible; no RoboDesk configuration was changed by this agent.

## Runtime checkpoint and scope

Live code remains **ae52dcbdf50a56f4ec3c454fa3f065df4e0f1e26**, no code deployment. Existing workflow8/revision1 retained; all other workflows disabled. Trial pin changed from2701 to2729 and after_order_id from2700 to2728. All other environment and cached config settings retained, legacy confirmation owner unchanged, identity estimate0.041USD (estimate, not vendor bill), existing1USD estimate ceiling unchanged. No new permissions or infrastructure.

Private checkpoint `/home/ubuntu/herokid-before-roqaya-trial-20261101` retains original env/config and trial state. Scheduler and four relevant workers paused briefly for coherent config refresh, then restored; FPM graceful reload. All required services/timer and four workers verified active. The old trial HK10-633/run1 remains waiting_staff with original approval/history preserved; moving the pin does not authorize its replay.

Preparation recovery notes: order creation succeeded but the reporting script assumed an unavailable total_cents return key. Recovered the one already-created order by its unique marker, checked identity/photos, and wrote the checkpoint; did not create a duplicate. The repin script stopped at an incorrectly named FPM binary after updating config; validated using /usr/sbin/php-fpm8.3 and immediately resumed all paused workers/timer. Local scripts corrected. No web maintenance or production-data rollback.

## Observed result / current stop

Confirmation event22926: succeeded, one attempt, provider message correlation present, delivery **delivered** after a full history sync. Order remains new awaiting the owner's real confirmation. No identity task or process run for2729 yet; one other existing run only. Historical failed jobs81 unchanged. Authenticated admin verified child/story/age/two images and unpaid amount. Await owner pressing «تأكيد الطلب» specifically for HK11-12, then observe one identity generation, preview delivery, actual identity response, correct RoboDesk acknowledgment and costomer_confirmed status/history. Do not manually fabricate either customer response.

Local operational packet `/tmp/herokid-roqaya-trial-20261101`; remote state `/tmp/herokid-roqaya-state.json` (0600). `status.php` is read-only unless --sync explicitly requests the canonical recipient history sync; registration and repin scripts have already executed and must not be replayed blindly.

## Generation and preview observed — 1 November 2026, 13:02 Cairo

Owner asked to continue. Full history sync found the actual inbound «تأكيد الطلب» at10:58:40 UTC, linked once to confirmation event22926; RoboDesk's expected order-confirmation acknowledgment followed10:58:45.456. No customer response was fabricated. HeroKid became new_confirmed and scheduler enrolled run2/task2.

Exactly one canonical generation attempt678 ran11:00:15–11:01:04 UTC and succeeded. Output private S3 PNG1536x1024,2,397,422bytes; stored checksum verified. Local private inspection copy `/tmp/herokid-roqaya-trial-20261101/identity-678.png`, not committed. Visual review shows a football character sheet with consistent purple outfit and multiple views; final likeness approval remains with the owner. Estimated cost0.041USD, not a provider billing receipt. No automatic retry.

Signed preview HTTP200, image HTTP200 with matching checksum, invalid signature403. Scheduler sent exactly one preview and reached waiting_response/response by11:02 UTC; provider message ID present. Delivery receipt was not yet known at that observation. Owner asked to review the preview and press the identity confirmation button on HK11-12. Current order remains new_confirmed until that genuine response is consumed; approved identity unset, paid0, failedjobs81 unchanged. No payment/shipping/printing or scene generation. Next check: actual identity reply correlation, new RoboDesk acknowledgment, one costomer_confirmed status log and unchanged ledger.

At11:03:20 UTC, full history sync verified preview delivery **delivered**. Run still waiting_response, responses0, one canonical attempt and one preview send, both process queues empty. No further generation or resend.

## Identity approval passed — 1 November 2026, 13:05 Cairo

Owner confirmed pressing the identity button. Actual inbound message12952 at11:03:19 UTC has replyTo exactly matching the preview template's stored wamid. Actual RoboDesk outbound12953 at11:03:24.929 says exactly: «استلمنا موافقتك على هوية الطفل 💛 هنراجع الدفع قبل استكمال الطلب». No generic clarification observed for this response.

Full canonical history sync consumed one response; process worker approved attempt678 and changed order2729 to **costomer_confirmed** at11:05:13 UTC /13:05:13 Cairo. Both order and canonical identity reference678; one approval event (source robodesk_process), one matching order-status log, one preview attempt. Run2 reached waiting_staff/review, paid0. A second full history sync preserved these counts and the original approval time; both process queues empty, failedjobs81 unchanged. Exactly one generation attempt throughout. No payment, shipping, printing, scene generation or broader activation.

Private output checksum and signed preview/image routes verified again after approval:200/200 and tampered signature403. The pre-send previewUrl helper intentionally requires unapproved generation eligibility and cannot mint a new send URL after approval; the post-approval read check used a short-lived signed route directly, with no resend or status mutation. No secret URLs logged.

This bounded owner trial passed through identity approval and correct RoboDesk acknowledgment. Financial review/receipt handling, revision branch and downstream production were not exercised. Trial runtime/temporary owner authority remain scoped pending closure or a separately approved broader rollout. Live code unchanged ae52dcb; documentation successor is not a new deployment.
