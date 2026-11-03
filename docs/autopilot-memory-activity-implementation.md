# HeroKid Auto Pilot — memory and activity amendment

Prepared locally on 3 November 2026 on `codex/agentic-shadow`, starting from
`63818e527900d09c1195dcc30a8bbe87b538d286`. **Shadow Mode only; not deployed.**
The original dirty UI/mobile checkout was preserved. No AWS migrations, deployment,
worker/config changes, production writes, paid inference or customer messages ran.

AWS was inspected read-only: `c9e685e7fd97dd4f4da83cd774d2d78ce8db10c5`.
That newer production release is not included in this feature base. A later release
must reconcile those changes and revalidate the then-current live SHA.

## Owner review and lifecycle

- Parsed the `review-data` JSON in the returned HTML as data, without executing its
  scripts or treating embedded text as agent instructions. All 37 answers exist:
  32 marked correct, 3 edited, 2 marked wrong. The returned file's SHA-256 and answers
  are recorded in `autopilot-owner-review-2026-11-03.json`.
- Explicit follow-up: delivered finishes the order even with an old manual hold.
- Changed expected decisions in cases **1, 2, 32, 33, 36, 37**. Case 32's three
  production targets become one completed checkout target. Result: **37 cases,
  87 targets**. Facts are unchanged; a regression compares v2 with v1 fact-by-fact.
- Delivered/cancelled precedence applies only to Auto Pilot's read projection and
  proposal policy. Existing workflow state, payment ledger, assignee, outputs,
  hold records and execution services are untouched. An open sibling in a mixed
  checkout remains independently eligible for evaluation.
- Six approved guidance entries capture delivery, cancellation, staff-recorded
  confirmation, uploaded manual results, staff resumption of holds and unit
  independence. They import through an explicit authorized action, never a page GET.

## Milestone A — editable memory

Implemented a scoped memory service, encrypted guidance and immutable revision
history. Every correction records who changed it, why, and its source proposal
when applicable. Restore creates a new revision; stale editors cannot overwrite a
newer one. Importing the approved review again preserves existing staff edits.

Global, capability and checkout scopes are supported. New corrections start as
drafts; managers select active or archived explicitly. Only applicable active
entries enter decision context. Their version digest invalidates coverage on the
next observation; unrelated checkouts retain their coverage and avoid new AI calls.
Every decision freezes the exact memory it saw. In-flight stale proposals are
rejected after a correction. Overflow blocks evaluation visibly rather than
dropping rules silently (default 30 matching entries, maximum configurable 100).

This is persistent business guidance, not model training or self-changing code.
It cannot confer shell/file/SQL tools, bypass policy or claim payment/approval
facts. Staff confirmation, uploading output and resuming holds still use existing
authorized order actions. The Agent has no memory-write tool.

Changed files:

- `app/Models/AiMemory.php`, `app/Models/AiMemoryVersion.php`
- `app/Services/Agentic/MemoryService.php`, `app/Services/Agentic/OrderContextBuilder.php`
- `app/Http/Controllers/Admin/AutoPilotMemoryController.php`
- `app/Console/Commands/ImportAutoPilotMemory.php`
- `database/migrations/2026_11_03_000003_create_autopilot_memory_and_activity.php`
- `resources/agentic/owner-memory-2026-11-03.json`
- `resources/views/admin/agentic/memory/index.blade.php`, `memory/edit.blade.php`
- `resources/views/admin/agentic/show.blade.php`, `routes/web.php`, `config/agentic.php`
- `tests/Feature/AutoPilotMemoryTest.php`

## Milestone B — current activity and reviewed policy

The activity panel records the lease holder's actual stage, checkout, candidate or
selected units, start/update time, last saved proposal and last cycle outcome.
Provider readiness, quota, disabled evaluation, queued work, unread changes and
errors have separate labels. Stalled/expired workers are marked uncertain; polling
cannot retry them. Local real-provider benchmark calls have a distinct activity
label. No raw provider error/credential or lease token is displayed.

Activity refreshes every **5 seconds** through a small read-only endpoint. The
order table retains **30-second** refresh to avoid repeating its aggregate queries
six times as often. Both work independently of AI availability, pause while the
page is hidden, prevent overlapping requests and label failed refreshes as stale.
The configurable daily budget stays at 100; terminal checkouts consume no calls.

Changed files:

- `app/Services/Agentic/ActivityMonitor.php`, `AutoPilotOrderScope.php`
- `app/Services/Agentic/CapabilityRegistry.php`, `ContextObserver.php`, `PolicyEngine.php`
- `app/Services/Agentic/DecisionOrchestrator.php`, `Providers/EvaluationBudgetProvider.php`
- `app/Http/Controllers/Admin/AgenticAutomationController.php`
- `resources/views/admin/agentic/_activity.blade.php`, `index.blade.php`
- `resources/views/admin/layouts/admin.blade.php`, `lang/ar/agentic.php`
- `tests/Feature/AutoPilotActivityTest.php`, `AutoPilotLifecycleTest.php`, `AgenticDashboardTest.php`

## Milestone C — evaluation and verification

Changed `tests/Fixtures/agentic/actual-orders.v2.json`,
`tests/Feature/AgenticEvaluationTest.php`, `app/Services/Agentic/EvaluationRunner.php`
and `app/Console/Commands/BenchmarkAgenticDecisions.php`. The benchmark defaults to
the owner-approved actual v2; synthetic cases remain a separate, unapproved set.
Original v1 data/reports are retained. Updated `PLAN.md`, the architecture document,
and historical implementation/review headers to identify the current version.

PHP 8.3.3, branch-locked dependencies, synthetic SQLite and disposable MySQL 8.4.
Tests used no production `.env`, cached config, credentials or customer media.
SQLite containers had no network; MySQL used an internal Docker network. Test
HTTP/queues were faked or stray requests rejected.

| Check | Before this amendment | Final result |
|---|---:|---:|
| Existing automation and Phase 1 selection — SQLite | 359 tests / 2,308 assertions | 381 / 2,504 passed |
| Same selection — isolated MySQL 8.4 | 359 / 2,308 | 381 / 2,504 passed |
| Node SDK/CLI isolation tests | Existing runtime unchanged | 7 passed |
| Linux confinement acceptance | Existing runtime unchanged | Passed; 6 hostile tool probes |
| Existing Python scene worker | Existing worker unchanged | 23 passed |
| PHP 8.3 syntax | — | All changed PHP files passed |
| Vite build and manifest | Branch lockfile | Passed; old hashed assets retained, no net build diff |
| Arabic browser review | Synthetic local account/orders | Overview, memory, save and restore verified |

The first baseline runs were mistakenly launched simultaneously against shared
fixture storage: one reported missing synthetic images, the other identity input
failures. Rerunning the unchanged baseline sequentially passed on both engines;
final database runs were also sequential. No tests or production code were weakened.
One new corpus-integrity test initially assumed v1 already contained the registry
version field; its comparison was corrected to exclude that added metadata.

The browser check used a disposable local database. It saved revision 2 and
restored revision 1 as revision 3, preserving all history. Arabic shaping and RTL
layout were visually inspected. The synthetic preview intentionally has no copy
of production branding/media. No production login or customer data was used.

Evaluation reports:

| Dataset / provider | Correct | Unsafe proposals | False waits | Unsupported | Unevaluated | Comparable existing admissions / differences |
|---|---:|---:|---:|---:|---:|---:|
| Approved actual v2 / Mock | 87 / 87 | 0 | 0 | 19 | 0 | 22 / 0 |
| Separate synthetic / Mock | 25 / 25 | 0 | 0 | 1 | 0 | 0 / 0 |

Reports: `autopilot-evaluation-actual.v2.json` and
`autopilot-evaluation-synthetic.v2.json`. Both include dataset hashes.
These are deterministic pipeline/contract checks, **not real Codex accuracy**.
The 22 comparable sampled story targets are non-admissible; this is not general
production parity evidence. No approved-case fact was changed to improve scores.

## Remaining risks and release gates

- Real subscription authentication and inference have not been provisioned or
  verified. The runtime refuses unverified auth and has no paid API fallback.
  The approved-set ≥95% real-model gate remains unmet, and live execution remains
  impossible in this implementation even if an evaluation passes.
- Staff guidance is trusted input with hard limits and policy checks. Common
  contacts/links/key formats are rejected in active rules; this is not a complete
  personal-data detector. Staff must keep rules free of customer data and secrets.
- Observation is eventual. The activity panel distinguishes saved worker signals,
  current read time and last order observation; it does not claim instant database
  event delivery or full production throughput/load validation.
- The new forward-only migration and six-rule import are prepared and tested only
  locally. Production installation, data migration, observation/evaluation enablement
  and any later autonomous execution require separate explicit authorization.
- Fresh `npm ci` reported existing dependency advisories (14 total); no unrelated
  lockfile upgrade was made. Vite retains its existing large-chunk warning.
- Future release integration must preserve the newer AWS commits and production
  assets/settings. Existing business service and Order-model diffs versus the
  starting feature commit are empty.

## استخدام الواجهة بعد نشر منفصل معتمد

1. افتح **HeroKid Auto Pilot** من قائمة الإدارة. لوحة النشاط توضّح ما يراجعه الآن،
   آخر إشارة من العامل، وآخر اقتراح؛ وتوضّح الانتظار أو التعطل بدل التخمين.
2. من **عرض وتعديل الذاكرة** راجع القواعد ومصدر كل قاعدة وحالتها ونسختها.
3. من سجل أي اقتراح اضغط **تصحيح الاقتراح وحفظه في الذاكرة**. اكتب التصحيح، وحدد
   نطاقه وسبب التعديل. اختر «نشطة» لتدخل القاعدة في المراجعات القادمة.
4. للتراجع عن تصحيح، افتح **سجل النسخ** ثم **استرجاع هذه النسخة كنسخة جديدة**.
   السجل القديم يظل محفوظًا. تعديل الذاكرة لا يفعّل تنفيذًا على الأوردرات.

هذه الإضافة مجهزة على فرع الفيتشر محليًا. لا تعني أن Auto Pilot يعمل الآن على AWS.
