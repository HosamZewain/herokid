import subprocess,hashlib
base=['curl','--silent','--show-error','--resolve','aws.hero-kid.com:443:127.0.0.1']
for route in ['/story-library/migration-synthetic-20261029/document','/api/v1/story-library/migration-synthetic-20261029/document']:
 for partial in [False,True]:
  cmd=base+(['-H','Range: bytes=0-99'] if partial else [])+['-D','/tmp/hk-test-headers','https://aws.hero-kid.com'+route]
  b=subprocess.check_output(cmd); h=open('/tmp/hk-test-headers').read().splitlines()
  print(route,'range' if partial else 'full',len(b),hashlib.sha256(b).hexdigest(),[x for x in h if x.lower().startswith(('http/','content-range:','content-length:','content-type:'))])
