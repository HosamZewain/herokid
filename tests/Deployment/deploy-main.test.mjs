import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync, spawnSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, writeFileSync, readFileSync, readdirSync, statSync, rmSync, realpathSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';

const script = resolve('scripts/deploy-main.sh');
const release = 'a'.repeat(40);
const previous = 'b'.repeat(40);

function fixture(overrides = {}) {
    const root = realpathSync(mkdtempSync(join(tmpdir(), 'herokid-main-deploy-test-')));
    const app = join(root, 'app');
    const bin = join(root, 'bin');
    const backups = join(root, 'backups');
    mkdirSync(join(app, 'storage/framework'), { recursive: true });
    mkdirSync(join(app, 'public/build'), { recursive: true });
    mkdirSync(bin);
    for (const file of ['artisan', 'composer.json', '.env']) writeFileSync(join(app, file), 'fixture');
    writeFileSync(join(app, 'public/build/asset.js'), 'fixture', { mode: 0o600 });
    const mock = `#!${process.execPath}
const fs = require('node:fs');
const path = require('node:path');
const cmd = path.basename(process.argv[1]);
const args = process.argv.slice(2);
const e = process.env;
fs.appendFileSync(e.TEST_LOG, JSON.stringify([cmd, ...args]) + '\\n');
if (cmd === 'git') {
  const a = args.join(' ');
  if (a === 'rev-parse --show-toplevel') console.log(e.HEROKID_APP_DIR);
  if (a === 'diff --quiet' && e.TEST_DIRTY) process.exit(1);
  if (a === 'rev-parse FETCH_HEAD') console.log(e.TEST_REMOTE || e.TEST_RELEASE);
  if (a === 'rev-parse HEAD') console.log(fs.existsSync(e.TEST_SWITCHED) ? e.TEST_RELEASE : e.TEST_PREVIOUS);
  if (args[0] === 'merge-base' && (e.TEST_DIVERGENT === 'deployed' || (e.TEST_DIVERGENT === 'main' && args[2] === 'main'))) process.exit(1);
  if (args[0] === 'show-ref' && e.TEST_NO_MAIN) process.exit(1);
  if (a.startsWith('diff --name-only') && e.TEST_SCHEMA) console.log('database/migrations/new.php');
  if (args[0] === 'archive') process.stdout.write('code fixture');
  if (args[0] === 'switch') fs.writeFileSync(e.TEST_SWITCHED, 'main');
  if (args[0] === 'merge') fs.writeFileSync(e.TEST_SWITCHED, 'main');
  if (a === 'branch --show-current') console.log(fs.existsSync(e.TEST_SWITCHED) ? 'main' : (e.TEST_CURRENT_BRANCH || 'codex/previous-release'));
}
if (cmd === 'php' && args[1] === 'tinker') fs.writeFileSync(path.join(e.HEROKID_RELEASE_BACKUP, 'database.sql'), 'SQL fixture');
if (cmd === 'php' && args[1] === 'route:cache' && e.TEST_FAIL_CACHE) process.exit(1);
`;
    for (const command of ['git', 'php', 'composer', 'flock']) writeFileSync(join(bin, command), mock, { mode: 0o755 });
    const result = spawnSync('/bin/bash', [script, release], {
        encoding: 'utf8',
        env: { ...process.env, PATH: `${bin}:${process.env.PATH}`, PHP_BIN: join(bin, 'php'), HEROKID_APP_DIR: app, HEROKID_BACKUP_ROOT: backups,
            TEST_LOG: join(root, 'calls'), TEST_SWITCHED: join(root, 'switched'), TEST_RELEASE: release, TEST_PREVIOUS: previous, ...overrides },
    });
    const calls = readFileSync(join(root, 'calls'), 'utf8').trim().split('\n').filter(Boolean).map(JSON.parse);
    return { root, app, backups, result, calls, cleanup: () => rmSync(root, { recursive: true, force: true }) };
}

test('main deploy script has valid bash syntax', () => execFileSync('/bin/bash', ['-n', script]));

test('real Git advances inactive main without force and rejects rewinding it', () => {
    const root = mkdtempSync(join(tmpdir(), 'herokid-main-fast-forward-test-'));
    const git = (...args) => execFileSync('git', args, { cwd: root, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
    try {
        git('init', '--initial-branch=main');
        git('config', 'user.name', 'Release Test');
        git('config', 'user.email', 'release-test@example.test');
        writeFileSync(join(root, 'fixture.txt'), 'old');
        git('add', 'fixture.txt');
        git('commit', '-m', 'old main');
        const oldCommit = git('rev-parse', 'HEAD');
        git('switch', '--create', 'feature');
        writeFileSync(join(root, 'fixture.txt'), 'new');
        git('commit', '-am', 'verified release');
        const newCommit = git('rev-parse', 'HEAD');
        git('fetch', '--no-tags', '.', `${newCommit}:refs/heads/main`);
        assert.equal(git('rev-parse', 'main'), newCommit);
        assert.equal(git('branch', '--show-current'), 'feature');
        assert.equal(spawnSync('git', ['fetch', '--no-tags', '.', `${oldCommit}:refs/heads/main`], { cwd: root }).status === 0, false);
        assert.equal(git('rev-parse', 'main'), newCommit);
        git('switch', 'main');
        assert.equal(readFileSync(join(root, 'fixture.txt'), 'utf8'), 'new');
        assert.equal(git('status', '--porcelain'), '');
    } finally { rmSync(root, { recursive: true, force: true }); }
});

for (const [name, env] of [
    ['tracked local changes', { TEST_DIRTY: '1' }],
    ['a remote commit different from the pinned release', { TEST_REMOTE: previous }],
    ['a release missing deployed commits', { TEST_DIVERGENT: 'deployed' }],
    ['divergent local main', { TEST_DIVERGENT: 'main' }],
    ['unreviewed migrations', { TEST_SCHEMA: '1' }],
]) {
    test(`refuses ${name} before backup/maintenance/checkout`, () => {
        const f = fixture(env);
        try {
            assert.notEqual(f.result.status, 0, f.result.stdout + f.result.stderr);
            assert.equal(f.calls.some(c => c[0] === 'php' || c[1] === 'switch' || c[1] === 'archive'), false);
        } finally { f.cleanup(); }
    });
}

for (const noMain of [false, true]) {
    test(`deploys pinned main with private backup and readable assets (${noMain ? 'create' : 'fast-forward'})`, () => {
        const f = fixture(noMain ? { TEST_NO_MAIN: '1' } : {});
        try {
            assert.equal(f.result.status, 0, f.result.stdout + f.result.stderr);
            assert.ok(f.calls.some(c => c[0] === 'git' && c[1] === 'switch' && (noMain ? c[2] === '--create' : c[2] === 'main')));
            if (!noMain) {
                const advance = f.calls.findIndex(c => c[0] === 'git' && c[1] === 'fetch' && c[2] === '--no-tags' && c[3] === '.' && c[4] === `${release}:refs/heads/main`);
                const checkout = f.calls.findIndex(c => c[0] === 'git' && c[1] === 'switch');
                assert.ok(advance >= 0 && checkout > advance, 'main is advanced without force before checking out its final snapshot');
            }
            assert.ok(f.calls.some(c => c[0] === 'php' && c[2] === 'up'));
            assert.equal(statSync(join(f.app, 'public/build/asset.js')).mode & 0o777, 0o644);
            const backup = readdirSync(f.backups).find(n => n.startsWith('release-'));
            assert.equal(statSync(join(f.backups, backup)).mode & 0o777, 0o700);
            assert.equal(statSync(join(f.backups, backup, '.env.backup')).mode & 0o777, 0o600);
            assert.equal(readFileSync(join(f.backups, backup, 'previous-commit.txt'), 'utf8').trim(), previous);
            assert.ok(statSync(join(f.backups, backup, 'database.sql.gz')).size > 0);
            assert.equal(f.calls.some(c => c[0] === 'npm' || c[1] === 'reset'), false);
        } finally { f.cleanup(); }
    });
}

test('already on main deploys by fast-forward without checking out an old snapshot', () => {
    const f = fixture({ TEST_CURRENT_BRANCH: 'main' });
    try {
        assert.equal(f.result.status, 0, f.result.stdout + f.result.stderr);
        assert.ok(f.calls.some(c => c[1] === 'merge' && c[2] === '--ff-only' && c[3] === release));
        assert.equal(f.calls.some(c => c[1] === 'switch'), false);
    } finally { f.cleanup(); }
});

test('failed cache rebuild never reopens the site or automatically restores data', () => {
    const f = fixture({ TEST_FAIL_CACHE: '1' });
    try {
        assert.notEqual(f.result.status, 0);
        assert.equal(f.calls.some(c => c[0] === 'php' && c[2] === 'up'), false);
        assert.match(f.result.stdout, /No automatic code or database rollback/);
    } finally { f.cleanup(); }
});
