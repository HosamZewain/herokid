#!/usr/bin/env python3
"""Root only orchestrates two restricted existing service accounts; no app code runs as root."""
import argparse
import fcntl
import json
import subprocess


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--apply', action='store_true')
    args = parser.parse_args()
    with open('/run/lock/herokid-codex-cleanup.lock', 'a') as lock:
        fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        producer = subprocess.Popen(['/usr/sbin/runuser', '-u', 'www-data', '--', '/usr/bin/php8.3', 'artisan',
                                     'codex-scenes:local-cleanup-plan', '--limit=10', '--hold-dispatch-lock'],
                                    cwd='/var/www/herokid', stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                                    stderr=subprocess.DEVNULL, text=True)
        try:
            line = producer.stdout.readline(1024 * 1024)
            plan = json.loads(line)
            command = ['/usr/sbin/runuser', '-u', 'herokid-codex', '--', '/usr/bin/python3', '/opt/herokid-codex-worker/cleanup.py']
            if args.apply:
                command.append('--apply')
            result = subprocess.run(command, input=json.dumps(plan), capture_output=True, text=True, timeout=60)
            if result.returncode:
                raise RuntimeError('local_cleanup_helper_failed')
            summary = json.loads(result.stdout)
            summary['s3_verified_runs'] = len(plan['runs'])
            summary['s3_skipped_runs'] = len(plan.get('skipped', []))
            message = json.dumps(summary, sort_keys=True)
            print(message)
            subprocess.run(['/usr/bin/logger', '-t', 'herokid-codex-cleanup', '--', message], check=True)
        finally:
            if producer.poll() is None:
                producer.stdin.write('release\n')
                producer.stdin.flush()
            producer.communicate(timeout=10)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        subprocess.run(['/usr/bin/logger', '-t', 'herokid-codex-cleanup', '--', json.dumps({'status': 'failed', 'error_class': type(error).__name__})])
        raise SystemExit(1)
