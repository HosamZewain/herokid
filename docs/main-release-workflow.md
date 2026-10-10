# Unified main release — 2026-10-10

`main` is the production release source. The consolidation fast-forwards the
existing main history; it does not replace it or apply old patches twice.

## Included

All previous main features remain, including reports, AWS/hybrid S3 media,
permanent order media, storefront redesign/image optimization, attribution,
expenses and database exports. The latest release lineage adds:

- WhatsApp conversations, notifications, human-reply reconciliation and audio.
- Scoped mobile commerce and product/story personalization API (`0c7f7a4`).
- Quick story addition and existing-child reuse (`9771a86`).
- Public asset permission deployment correction (`a228313`).
- Confirmed, reason-required, audited and recoverable order item removal (`f8b0dc2`).

Old feature branches already included in this lineage do not need to be merged
again. Some historical branches use equivalent cherry-picked commits rather
than the same commit IDs. Their names are retained for reference, not used as
deployment sources.

## Deliberately excluded

- Old alternative homepage designs, superseded by the approved storefront.
- Uncommitted local work in the primary checkout, including mobile library and
  other unfinished changes.
- `amir-robodesk-integration`: a separate action/confirmation integration with
  new migrations, callback authentication and approval-flow changes. It is not
  the deployed conversations feature, and needs its own approval and review.

Do not merge these wholesale merely to remove branch names.

## Next releases

Start new completed feature work from updated `origin/main`, review its diff,
test against an isolated database, and merge/push it to `main` before deployment.
Do not deploy a feature branch or overwrite main history. Git does not
automatically merge future branches; a reviewed main merge remains required.
Leave unrelated dirty worktrees untouched. Rebuild and commit `public/build`
when frontend sources change; Hostinger does not need Node to deploy them.

## Deployment

Fetch `origin/main`, verify the full reviewed commit ID, then read the deployment
script from that exact commit and execute it. `scripts/deploy-main.sh`:

- Refuses tracked server changes, existing maintenance mode, changed remote
  release, omitted deployed commits or a divergent local main.
- Refuses new migrations/dependency definitions without a separate reviewed
  deployment plan. This release has neither relative to `9771a86`.
- Backs up code, environment and database outside the web root, privately.
- Switches to actual `main` and advances it only by fast-forward.
- Uses committed assets, makes only `public/build` readable, refreshes caches,
  restarts queues and exits maintenance on success.
- Does not delete media, seed data, change secrets, or auto-restore the database.

If it fails after entering maintenance, inspect the error before reopening.
Never restore a database backup automatically: new orders/payments could be lost.
The existing scheduler/queue wrapper remains unchanged.

After deployment verify `git branch --show-current` is `main` and HEAD matches
the release, load storefront assets (no 403), check add-story/product and remove
confirmation, audit/restore and totals using authorized non-production fixtures
where possible. Do not create accidental print/payment jobs. Run the mobile
`npm run release:site-check` from HeroKid-Mobile for the public contract check.
