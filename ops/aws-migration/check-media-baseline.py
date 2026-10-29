#!/usr/bin/env python3
"""Compare final S3 inventory against source references and a captured pre-migration baseline.
Read-only. Never treats a newly missing file as a historical exception.
Usage: check-media-baseline.py baseline-references.json baseline-objects.json final-references.json final-objects.json
All input files contain private paths; keep them mode 0600 and out of Git.
"""
import json,sys,collections
from pathlib import Path
before,old_objects,current,objects=[json.loads(Path(p).read_text()) for p in sys.argv[1:]]
def identity(r): return (r['table'],str(r['id']),r['disk'],r['path'])
known={identity(r) for r in before['references'] if r['disk']!='public' and not r['source_exists'] and r['key'] not in old_objects}
counts=collections.Counter()
for r in current['references']:
    if r['disk']=='public': continue
    obj=objects.get(r['key'])
    if obj:
        counts['size_mismatch' if r['source_exists'] and obj['Size']!=r['size'] else 'present']+=1
    elif not r['source_exists'] and identity(r) in known:
        counts['unchanged_historical_missing']+=1
    else:
        counts['new_or_unsynced_missing']+=1
print(json.dumps(dict(counts),sort_keys=True))
sys.exit(1 if counts['size_mismatch'] or counts['new_or_unsynced_missing'] else 0)
