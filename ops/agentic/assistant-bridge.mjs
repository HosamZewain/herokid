import { createServer } from 'node:net';
import { chmod } from 'node:fs/promises';
import { StringDecoder } from 'node:string_decoder';
import { socketPath } from './isolation.mjs';
import { AssistantRuntime } from './assistant-runtime.mjs';

const runtime = new AssistantRuntime();
await runtime.initialize();
const server = createServer(socket => {
  let body='',bytes=0,received=false;
  const decoder=new StringDecoder('utf8');
  socket.setTimeout(30000,()=>socket.destroy()); socket.on('error',()=>{});
  socket.on('data',async chunk=>{
    if(received) return socket.destroy();
    bytes+=chunk.length; if(bytes>16000) return socket.destroy();
    body+=decoder.write(chunk); if(!body.includes('\n')) return;
    received=true;
    try {
      if(!body.endsWith('\n')||body.indexOf('\n')!==body.length-1) throw new Error('INVALID_INPUT');
      const request=JSON.parse(body);
      const result=request.operation==='health' ? {...await runtime.health(),purpose:'admin_assistant'}
        : request.operation==='assistant_plan' ? await runtime.plan(request.input) : {error:'UNKNOWN_OPERATION'};
      socket.end(JSON.stringify(result)+'\n');
    } catch { socket.end(JSON.stringify({error:'ASSISTANT_UNAVAILABLE'})+'\n'); }
  });
});
// Drain bounded in-flight reads and release our own socket on a graceful stop.
// Never unlink an existing socket at startup: it may belong to a live process.
for (const signal of ['SIGTERM', 'SIGINT']) {
  process.once(signal, () => server.close(() => process.exit(0)));
}
server.listen(socketPath,async()=>chmod(socketPath,0o660));
