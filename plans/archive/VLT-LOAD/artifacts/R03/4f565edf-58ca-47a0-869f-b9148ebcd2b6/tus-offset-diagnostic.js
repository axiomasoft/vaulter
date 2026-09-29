import http from 'k6/http';
import { check } from 'k6';
import { API, TIER } from './lib/config.js';
import { STORAGE_ID, ACTOR_OWNER } from './lib/dataset.js';
import { actorHeaders } from './lib/auth.js';
import { tusChunk, saltedFirstChunk } from './lib/fixtures.js';
import { header } from './lib/headers.js';
export const options = { vus: 1, iterations: 3, maxDuration: '15m' };
export default function () {
  const headers = { ...actorHeaders(ACTOR_OWNER), 'Tus-Resumable': '1.0.0' };
  const total = 160 * 1024 * 1024;
  const create = http.post(`${API}/storages/${STORAGE_ID}/resumable-uploads`, JSON.stringify({name: `r03-attribution-${__ITER}-${Date.now()}.bin`, total_size_bytes: total}), {headers: {...headers, 'Content-Type': 'application/json'}, tags: {op:'OP-01', tier:TIER}});
  if (!check(create, {'create 201': r=>r.status===201})) return;
  const id = create.json('data.upload_id');
  let offset=0;
  for (let i=0; i<20; i++) {
    const payload=i===0?saltedFirstChunk(tusChunk(),id):tusChunk();
    const started=Date.now();
    const res=http.patch(`${API}/resumable-uploads/${id}`, payload, {headers:{...headers,'Content-Type':'application/offset+octet-stream','Upload-Offset':String(offset)}, tags:{op:'OP-02',tier:TIER,offset_bytes:String(offset)}, responseType: 'text'});
    console.log(JSON.stringify({kind:'r03-tus-offset',attempt:__ITER+1,upload_id:id,index:i,offset_bytes:offset,chunk_bytes:payload.byteLength,status:res.status,wall_ms:Date.now()-started,duration_ms:res.timings.duration,waiting_ms:res.timings.waiting}));
    if(!check(res,{'PATCH accepted':r=>r.status===204||r.status===201})) break;
    if(res.status===201) {
      const node=res.json('data');
      const download=http.get(`${API}/nodes/${node.id}/download`,{headers,tags:{op:'OP-08',tier:TIER},responseType:'none'});
      console.log(JSON.stringify({kind:'r03-completed-node',attempt:__ITER+1,node_id:node.id,status:download.status,content_length:header(download,'Content-Length')}));
      check(download,{'completed real upload downloads 200':r=>r.status===200});
      break;
    }
    const accepted=parseInt(header(res,'Upload-Offset'),10);
    if(!Number.isFinite(accepted)||accepted!==offset+payload.byteLength) throw new Error('Unexpected server offset');
    offset=accepted;
  }
}
export function handleSummary(data) {return { [__ENV.R03_SUMMARY_PATH]:JSON.stringify(data,null,2),stdout:JSON.stringify({diagnostic:'3 x 160 MiB; not an SLO proof'})+'\n'};}
