#!/usr/bin/env python3
"""Bounded, resumable S3 delta transfer. Default is a plan. Never deletes remote data.
Run on the trusted SSH controller, after capturing inventory.php and S3 list_objects_v2.
Production --apply is GO-LIVE gated; staging uses only synthetic fixtures.
"""
import argparse, hashlib, json, mimetypes, os, pathlib, shlex, shutil, subprocess, tempfile, time

PROD = 'herokid-prod-media-2026'
STAGE = 'herokid-staging-media-645314607155'

def safe_path(value):
    p = pathlib.PurePosixPath(value)
    if not value or p.is_absolute() or any(x in ('..', '.') for x in p.parts) or '\x00' in value or '\n' in value or '\r' in value:
        raise ValueError('Unsafe media path')
    return value

def plan(files, objects):
    result = []
    for f in files:
        path = safe_path(f['path'])
        if f['scope'] == 'public' or (f['scope'] == 'private' and path.startswith('expenses/')):
            continue  # These remain local; separate rsync, never S3.
        if f['scope'] not in ('private', 'studio-private'):
            raise ValueError('Unexpected scope')
        key = ('private/studio-private/' if f['scope'] == 'studio-private' else 'private/') + path
        o = objects.get(key)
        if o is None or int(o['Size']) != int(f['size']) or float(o['LastModifiedEpoch']) < float(f['mtime']):
            result.append({**f, 'key': key})
    return result

def main():
    p = argparse.ArgumentParser(description=__doc__)
    for name in ['inventory', 'objects', 'source-key', 'aws-key']:
        p.add_argument('--'+name, required=True)
    p.add_argument('--bucket', choices=[PROD, STAGE], default=PROD)
    p.add_argument('--source-host', default='u470070883@194.55.133.161')
    p.add_argument('--source-port', type=int, default=65002)
    p.add_argument('--source-root', default='/home/u470070883/domains/hero-kid.com/public_html/storage/app')
    p.add_argument('--aws-host', default='ubuntu@63.188.124.6')
    p.add_argument('--journal', required=True)
    p.add_argument('--apply', action='store_true')
    p.add_argument('--go-live-approved', action='store_true', help='Set only AFTER explicit human GO-LIVE approval')
    a = p.parse_args(); os.umask(0o077)
    files = json.loads(pathlib.Path(a.inventory).read_text())['files']
    objects = json.loads(pathlib.Path(a.objects).read_text())
    rows = plan(files, objects)
    print(json.dumps({'mode': 'apply' if a.apply else 'plan', 'files': len(rows), 'bytes': sum(x['size'] for x in rows), 'bucket': a.bucket}))
    if not a.apply: return
    if a.bucket == PROD and not a.go_live_approved:
        raise SystemExit('Production transfer blocked: explicit GO-LIVE approval required')
    if a.bucket == STAGE and any(not r['path'].startswith('migration-synthetic/') for r in rows):
        raise SystemExit('Staging accepts only migration-synthetic fixtures')
    sshaws = ['ssh', '-o', 'BatchMode=yes', '-i', os.path.expanduser(a.aws_key), a.aws_host]
    sshsource = ['ssh', '-o', 'BatchMode=yes', '-p', str(a.source_port), '-i', os.path.expanduser(a.source_key)]
    def aws(args, stream=None):
        proc = subprocess.run(sshaws + [shlex.join(['aws', '--region', 'eu-central-1'] + args)], stdin=stream, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        if proc.returncode: raise RuntimeError('AWS command failed; inspect privately, no credentials or media paths logged')
        return proc.stdout
    identity = json.loads(aws(['sts', 'get-caller-identity']))
    expected = 'HeroKid-Prod-EC2-S3-Role' if a.bucket == PROD else 'HeroKid-Staging-EC2-S3-Role'
    if identity['Account'] != '645314607155' or '/'+expected+'/' not in identity['Arn']:
        raise SystemExit('Unexpected IAM role/account')
    with tempfile.TemporaryDirectory(prefix='herokid-delta-') as tmp, open(a.journal, 'a') as journal:
        for index, r in enumerate(rows, 1):
            # One file at a time bounds controller disk use, including files larger than a batch.
            if shutil.disk_usage(tmp).free < r['size'] + 1024**3:
                raise RuntimeError('Insufficient controller disk headroom')
            listing = pathlib.Path(tmp, 'files'); listing.write_bytes(r['path'].encode()+b'\0')
            dest = pathlib.Path(tmp, 'data'); dest.mkdir(exist_ok=True)
            source = a.source_host+':'+shlex.quote(a.source_root+'/'+r['scope']+'/')
            proc = subprocess.run(['rsync', '-a', '--from0', '--files-from='+str(listing), '-e', shlex.join(sshsource), source, str(dest)+'/'], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            if proc.returncode: raise RuntimeError('Source transfer failed; final source may have changed')
            path = dest.joinpath(*pathlib.PurePosixPath(r['path']).parts)
            if path.is_symlink() or not path.is_file() or path.stat().st_size != r['size'] or int(path.stat().st_mtime) != int(r['mtime']):
                raise RuntimeError('Source changed since inventory; regenerate frozen inventory')
            digest = hashlib.sha256()
            with path.open('rb') as f:
                for chunk in iter(lambda: f.read(1024*1024), b''): digest.update(chunk)
            checksum = digest.hexdigest()
            content_type = mimetypes.guess_type(r['path'])[0] or 'application/octet-stream'
            with path.open('rb') as f:
                aws(['s3', 'cp', '-', 's3://'+a.bucket+'/'+r['key'], '--expected-size', str(r['size']), '--content-type', content_type, '--metadata', 'sha256='+checksum, '--only-show-errors'], f)
            head = json.loads(aws(['s3api', 'head-object', '--bucket', a.bucket, '--key', r['key']]))
            if head['ContentLength'] != r['size'] or head.get('Metadata', {}).get('sha256') != checksum:
                raise RuntimeError('Uploaded object verification failed')
            journal.write(json.dumps({'key':r['key'], 'size':r['size'], 'sha256':checksum, 'version':head.get('VersionId'), 'time':int(time.time())})+'\n')
            journal.flush(); os.fsync(journal.fileno())
            shutil.rmtree(dest)  # Only this script's local temporary download, never the source.
            print('verified', index, 'of', len(rows), flush=True)

if __name__ == '__main__': main()
