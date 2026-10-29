#!/usr/bin/env python3
"""Stream only the MariaDB client sandbox directive out for MySQL CLI compatibility.
Preserve every schema/data byte otherwise. Use pipefail and retain the original archive.
"""
import sys
for line in sys.stdin.buffer:
    if line.startswith(b'/*M!999999\\- enable the sandbox mode */'):
        continue
    sys.stdout.buffer.write(line)
