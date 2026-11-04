#!/usr/bin/env python3
"""Prepare narrowly scoped configs; never install/reload or overwrite the inputs."""
from pathlib import Path
import argparse


def fpm(source):
    old = 'pm.max_children = 3\n'
    if source.count(old) != 1 or '\npm.status_listen =' in source or '\npm.status_path =' in source:
        raise ValueError('Revalidate the current FPM capacity/status config before applying')
    return source.replace(old, 'pm.max_children = 6\n') + (
        '\n; HeroKid: bounded web capacity; summary status is Unix-only, never exposed by Nginx.\n'
        'pm.status_path = /fpm-status\n'
        'pm.status_listen = /run/php/herokid-fpm-status.sock\n'
    )


def nginx(source):
    marker = '    access_log /var/log/nginx/herokid-production-access.log;\n'
    if source.count(marker) != 1 or 'herokid-latency.log' in source or 'location ^~ /build/assets/' in source:
        raise ValueError('Revalidate the current production Nginx config before applying')
    prefix = ("# No client IP, URI, query, cookies or customer payload in latency telemetry.\n"
              "log_format herokid_latency '$msec $status $request_time $upstream_response_time $bytes_sent';\n")
    directives = r'''    gzip_vary on;
    gzip_types text/css application/javascript application/json image/svg+xml font/ttf font/otf;
    location = /fpm-status { return 404; }
    # Build filenames are content hashes; preserve old assets during every release.
    location ~ ^/build/assets/[^/]+-[A-Za-z0-9_-]+\.(?:css|js|svg|png|jpe?g|webp|gif|woff2?|ttf|otf|wasm)$ {
        try_files $uri =404;
        add_header Cache-Control "public, max-age=31536000, immutable";
        add_header X-Content-Type-Options nosniff always;
        add_header X-HeroKid-Origin aws always;
    }
    access_log /var/log/nginx/herokid-latency.log herokid_latency;
'''
    return prefix + source.replace(marker, directives + marker)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('kind', choices=['fpm', 'nginx'])
    parser.add_argument('source', type=Path)
    parser.add_argument('destination', type=Path)
    args = parser.parse_args()
    if args.source.resolve() == args.destination.resolve() or args.destination.exists():
        raise SystemExit('Use a new staged destination; inputs are never overwritten')
    args.destination.write_text(globals()[args.kind](args.source.read_text()))
