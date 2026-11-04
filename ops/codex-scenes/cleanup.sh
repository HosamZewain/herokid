#!/bin/bash
set -euo pipefail
umask 077
[[ "${1:---dry-run}" == --apply || "${1:---dry-run}" == --dry-run ]]
args=()
[[ "${1:---dry-run}" == --apply ]] && args+=(--apply)
exec /usr/bin/timeout --signal=TERM --kill-after=15s 20m /usr/bin/python3 /opt/herokid-codex-worker/cleanup-orchestrator.py "${args[@]}"
