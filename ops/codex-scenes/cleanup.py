#!/usr/bin/env python3
"""Delete only old, completed, byte-identical image copies attested by Laravel/S3.

No cloud/database credentials, recursive removal, run-state changes or generation.
Status/manifest/checkpoint/diagnostic files remain to prevent historical redispatch.
"""
import argparse
import fcntl
import hashlib
import json
import os
import re
import stat
import sys
import time
from pathlib import Path

UUID = re.compile(r'^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$')
SHA = re.compile(r'^[0-9a-f]{64}$')
SCENE = re.compile(r'^scene-([0-9]{2,4})\.png$')


def plain_dir(path):
    return path.is_dir() and not path.is_symlink() and path.resolve() == path.absolute()


def digest(path):
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd, 'rb') as stream:
        info = os.fstat(stream.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
            raise ValueError('unsafe_file')
        h = hashlib.sha256()
        for chunk in iter(lambda: stream.read(1024 * 1024), b''):
            h.update(chunk)
        return h.hexdigest(), info


def clean(plan, home, spool, apply=False, now=None):
    now = time.time() if now is None else now
    if (plan.get('version') != 1 or type(plan.get('generated_at')) is not int
            or not -5 <= now - plan['generated_at'] <= 300
            or type(plan.get('retention_seconds')) is not int or plan['retention_seconds'] < 172800
            or not isinstance(plan.get('runs'), list) or len(plan['runs']) > 100):
        raise ValueError('invalid_or_expired_plan')
    cutoff = now - plan['retention_seconds']
    jobs, inboxes, outboxes = home / 'jobs', spool / 'inbox', spool / 'outbox'
    for root in [home, spool, jobs, inboxes, outboxes]:
        if not plain_dir(root):
            raise ValueError('unsafe_root')
    result = {'apply': apply, 'eligible_runs': 0, 'candidate_files': 0, 'deleted_files': 0,
              'candidate_bytes': 0, 'deleted_bytes': 0, 'skipped_runs': 0, 'skipped_files': 0, 'skip_reasons': {}}
    accepted_hashes = set()
    receipts = []
    seen_ids = set()

    def remove(path, expected):
        try:
            actual, before = digest(path)
            if actual != expected or before.st_mtime > cutoff:
                raise ValueError('changed_or_recent_file')
            current = path.lstat()
            if (before.st_ino, before.st_dev, before.st_size, before.st_mtime_ns) != (current.st_ino, current.st_dev, current.st_size, current.st_mtime_ns):
                raise ValueError('file_changed')
            result['candidate_files'] += 1
            result['candidate_bytes'] += before.st_size
            if apply:
                path.unlink()
                result['deleted_files'] += 1
                result['deleted_bytes'] += before.st_size
        except FileNotFoundError:
            pass
        except (OSError, ValueError):
            result['skipped_files'] += 1

    for run in plan['runs']:
        try:
            key = run['id']
            scenes = run['scenes']
            if (not UUID.fullmatch(key) or key in seen_ids or type(run.get('order_id')) is not int
                    or type(run.get('finished_at')) is not int or run['finished_at'] > cutoff
                    or type(run.get('scene_count')) is not int or not 1 <= run['scene_count'] <= 1000
                    or not isinstance(scenes, dict) or set(scenes) != {str(n) for n in range(1, run['scene_count'] + 1)}
                    or any(not isinstance(v, str) or not SHA.fullmatch(v) for v in scenes.values())):
                raise ValueError('invalid_run')
            seen_ids.add(key)
            inbox, out, work = inboxes / key, outboxes / key, jobs / key
            if not all(plain_dir(p) for p in [inbox, out, work]):
                raise ValueError('unsafe_run_directory')
            state_file = out / 'status.json'
            manifest_file = inbox / 'manifest.json'
            for file in [state_file, manifest_file]:
                if file.is_symlink() or file.stat().st_size > 4 * 1024 * 1024:
                    raise ValueError('unsafe_metadata')
            state = json.loads(state_file.read_text())
            manifest_bytes = manifest_file.read_bytes()
            manifest = json.loads(manifest_bytes)
            if (state.get('id') != key or state.get('status') != 'completed' or state_file.stat().st_mtime > cutoff
                    or manifest.get('id') != key or manifest.get('order_id') != run['order_id']
                    or manifest.get('scene_count') != run['scene_count']
                    or any((inbox / marker).exists() for marker in ['cancel', 'pause-after-scene'])):
                raise ValueError('worker_not_settled')
            if state.get('manifest_sha256') is None:
                # Early protocol 2/3/4 workers did not publish a manifest digest.
                # Require approved identity binding and EVERY saved page receipt;
                # the actual local/S3 image hash is still checked before unlink.
                identity = run.get('identity_checksum')
                if (manifest.get('protocol') not in [2, 3, 4] or manifest.get('protocol') != run.get('protocol')
                        or not isinstance(identity, str) or not SHA.fullmatch(identity)
                        or manifest.get('identity_checksum') != identity):
                    raise ValueError('legacy_binding_missing')
                for number, sha in scenes.items():
                    meta_path = out / f'scene-{int(number):02d}.json'
                    if (meta_path.is_symlink() or not meta_path.is_file() or meta_path.stat().st_size > 65536
                            or meta_path.stat().st_mtime > cutoff):
                        raise ValueError('legacy_binding_missing')
                    meta = json.loads(meta_path.read_text())
                    if (meta.get('id') != key or meta.get('order_id') != run['order_id']
                            or meta.get('scene') != int(number) or meta.get('identity_checksum') != identity
                            or meta.get('sha256') != sha):
                        raise ValueError('legacy_binding_mismatch')
            elif state['manifest_sha256'] != hashlib.sha256(manifest_bytes).hexdigest():
                raise ValueError('manifest_changed')
            # Only numbered accepted images below are candidates; rejected or unknown files remain.
            result['eligible_runs'] += 1
            receipt = {'version': 1, 'id': key, 'verified_at': int(now), 'local_images_removed': True, 'shared_cache_done': False}
            receipts.append((out / 'local-cleanup.json', receipt))
            for number, sha in scenes.items():
                name = f'scene-{int(number):02d}.png'
                for folder in [work, out, inbox]:
                    if not plain_dir(folder):
                        raise ValueError('directory_changed')
                    remove(folder / name, sha)
                accepted_hashes.add(sha)
                if apply and any((folder / name).exists() or (folder / name).is_symlink() for folder in [work, out, inbox]):
                    receipt['local_images_removed'] = False
        except (OSError, ValueError, KeyError, TypeError) as error:
            result['skipped_runs'] += 1
            known = {'invalid_run', 'unsafe_run_directory', 'unsafe_metadata', 'worker_not_settled', 'manifest_changed', 'legacy_binding_missing', 'legacy_binding_mismatch'}
            reason = str(error) if isinstance(error, ValueError) and str(error) in known else type(error).__name__
            result['skip_reasons'][reason] = result['skip_reasons'].get(reason, 0) + 1

    # The shared image tool cache is touched only when no worker run is in flight.
    idle = plan.get('allow_generated_cache') is True
    if idle:
        for folder in inboxes.iterdir():
            if folder.is_dir() and UUID.fullmatch(folder.name) and (folder / 'manifest.json').exists() and not (outboxes / folder.name / 'status.json').exists():
                idle = False
                break
    if idle:
        for folder in outboxes.iterdir():
            if not folder.is_dir() or not UUID.fullmatch(folder.name):
                continue
            try:
                state_file = folder / 'status.json'
                if not plain_dir(folder) or state_file.is_symlink():
                    idle = False
                    break
                state = json.loads(state_file.read_text())
                if state.get('status') in ['running', 'queued', 'preparing', 'paused']:
                    idle = False
                    break
            except (OSError, ValueError):
                idle = False
                break
    generated = home / '.codex' / 'generated_images'
    if idle and accepted_hashes and plain_dir(generated):
        for file in generated.iterdir():
            if file.suffix.lower() != '.png' or file.is_symlink():
                continue
            try:
                if file.stat().st_mtime <= cutoff:
                    sha, _ = digest(file)
                    if sha in accepted_hashes:
                        remove(file, sha)
            except (OSError, ValueError):
                result['skipped_files'] += 1
    if apply:
        for path, receipt in receipts:
            receipt['shared_cache_done'] = idle
            temp = path.with_suffix('.json.tmp')
            try:
                fd = os.open(temp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o640)
                os.fchmod(fd, 0o640)  # Root wrapper's umask 077 must not hide receipts from the bridge group.
                with os.fdopen(fd, 'w') as stream:
                    json.dump(receipt, stream, sort_keys=True)
                temp.replace(path)
            except OSError:
                result['skipped_files'] += 1
    result['shared_cache_idle'] = idle
    return result


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--apply', action='store_true')
    args = parser.parse_args()
    plan = json.loads(sys.stdin.buffer.read(1024 * 1024))
    home = Path('/var/lib/herokid-codex')
    spool = Path('/var/lib/herokid-codex-spool')
    with (home / 'cleanup.lock').open('a') as lock:
        fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        print(json.dumps(clean(plan, home, spool, args.apply), sort_keys=True))


if __name__ == '__main__':
    main()
