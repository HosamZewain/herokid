#!/bin/bash
set -euo pipefail
umask 077

APP_DIR="/home/u470070883/domains/hero-kid.com/public_html"
PHP_BIN="/usr/bin/php"
cd "$APP_DIR" || exit 1
mkdir -p storage/logs
exec 9>storage/order-automation-worker.lock
flock -n 9 || exit 0

# Admission is gated by ORDER_AUTOMATION_ENABLED and each workflow's switch.
# Explicit no-cost simulations / approved single-order trials also use this queue.
"$PHP_BIN" artisan order-automation:tick >> storage/logs/order-automation.log 2>&1
"$PHP_BIN" artisan queue:work order-automation --queue=order-automation --stop-when-empty --tries=1 --timeout=240 --max-time=50 >> storage/logs/order-automation.log 2>&1
