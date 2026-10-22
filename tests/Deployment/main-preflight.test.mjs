import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { test } from 'node:test';
import assert from 'node:assert/strict';

const source = readFileSync(new URL('../../scripts/deploy-main.sh', import.meta.url), 'utf8');
const block = source.slice(source.indexOf('PUSH_MIGRATION='), source.indexOf('\nmkdir -p "$BACKUP_ROOT"'));
const allowed = 'database/migrations/2026_10_15_000100_create_mobile_push_campaigns.php';
const library = 'database/migrations/2026_10_15_000200_create_public_story_library.php';
const appMetrics = 'database/migrations/2026_10_17_000100_create_app_landing_events.php';
const productDeletion = 'database/migrations/2026_10_21_000200_add_soft_deletes_to_products.php';
const orderAutomation = 'database/migrations/2026_10_22_000100_create_order_automations.php';
function preflight(diff, exitCode = '0') {
    return spawnSync('/bin/bash', ['-se'], {
        input: `set -euo pipefail\nPREVIOUS_COMMIT=previous\nRELEASE_COMMIT=release\ngit() { printf '%s' "$MIGRATION_DIFF"; return "$GIT_EXIT_CODE"; }\n${block}\nprintf 'guard-passed\\n'\n`,
        env: { ...process.env, MIGRATION_DIFF: diff, GIT_EXIT_CODE: exitCode },
        encoding: 'utf8',
    });
}
test('deployment preflight avoids /dev/fd process substitution', () => {
    assert.ok(block.includes('CHANGED_MIGRATIONS='));
    assert.ok(block.includes('done <<HEROKID_MIGRATION_LIST'));
    assert.doesNotMatch(source, /<\s*<\(/);
});
test('piped bash accepts no migrations and the reviewed migration', () => {
    for (const diff of ['', allowed, `${allowed}\n`, library, `${allowed}\n${library}`, appMetrics, `${allowed}\n${library}\n${appMetrics}`, productDeletion, `${appMetrics}\n${productDeletion}`, orderAutomation, `${productDeletion}\n${orderAutomation}`]) {
        const result = preflight(diff);
        assert.equal(result.status, 0, result.stderr);
        assert.match(result.stdout, /guard-passed/);
    }
});
test('piped bash rejects unreviewed migrations before deployment', () => {
    const result = preflight(`${allowed}\ndatabase/migrations/unreviewed.php`);
    assert.notEqual(result.status, 0);
    assert.match(result.stdout, /unreviewed schema/);
    assert.doesNotMatch(result.stdout, /guard-passed/);
});
test('git diff failure cannot silently pass the migration guard', () => {
    const result = preflight('', '2');
    assert.equal(result.status, 2);
    assert.doesNotMatch(result.stdout, /guard-passed/);
});
