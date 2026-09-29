import copy, json, sys
from contextlib import ExitStack
from pathlib import Path
from unittest.mock import patch
sys.path.insert(0, str(Path.cwd()/'load/gates'))
import p7_live_matrix as live
import p7_chaos as gate
import test_p7_evidence as ev
from test_p7_integrity_contract import IntegrityContractTest

def probe(kind):
    cell=next(c for c in gate.catalog() if c['cell_id']=='product-realistic.s3-complete.503')
    fixture={'run_id':ev.RUN,'marker':live.product_ops.marker_for(ev.RUN,cell['cell_id'],1),
             'upload_id':'b17-upload','payload_sha256':ev.CHECKSUM,'requested_bytes':16,'cas_scope':'global'}
    upload={'entity':'upload_session','entity_id':'session-1','upload_id':'b17-upload',
            'status':'uploading','received_bytes':16,'assembled_blob_id':None}
    entities=[upload]
    if kind=='wrong-final-content':
        upload.update(status='committed',assembled_blob_id='blob-1')
        entities.append({'entity':'blob','entity_id':'blob-1','status':'committed','storage_key':ev.FINAL_KEY,'checksum':'c'*64,'size':16})
    calls=[]
    def sample(key, expected_checksum=None):
        calls.append(key)
        exists=kind=='wrong-final-content' and key==ev.FINAL_KEY and len(calls)>1
        return {'key':key,'exists':exists,'checksum':'c'*64 if exists else None,'size':16 if exists else 0,
                'observation':'observed' if exists else 'absent'}
    streams={name:[] for name in gate.JSONL_FILES}
    with ExitStack() as stack:
        stack.enter_context(patch.object(live,'_run',side_effect=AssertionError('no live boundary')))
        for name,value in {'host_status':200,'host_probe':(200,'vaulter_metric 1'),'arm':'s3-fault-proxy',
                           'barrier_ack':ev.ack_for(cell),'revert':None,'cleanup_fixture':None,
                           'sample_entities':entities,'object_listing':{'status':'observed','keys':[]}}.items():
            stack.enter_context(patch.object(live,name,return_value=value))
        stack.enter_context(patch.object(live,'object_sample',side_effect=sample))
        stack.enter_context(patch.object(live.product_ops.ProductOperationRunner,'prepare',return_value=fixture))
        stack.enter_context(patch.object(live.product_ops.ProductOperationRunner,'execute',return_value={
            'operation':'s3-complete','status':201 if kind=='wrong-final-content' else 503,
            'transport':'http','request_id':'b17-control','requested_bytes':0,'written_bytes':None}))
        record=live.run_window(streams,live.Clock(),cell,1,ev.RUN,gate.config_fingerprint(),'a'*64)
    reader='accepted'
    try: IntegrityContractTest('runTest').admit(record,streams)
    except gate.EvidenceInvalid as e: reader='rejected: '+str(e)
    return {'control':kind,'producer':record['evidence'],'full_reader':reader,'received_bytes':upload['received_bytes'],
            'payload_checksum':ev.CHECKSUM,'joins':[{k:r[k] for k in ('object_key','status')} for r in streams['reconcile.jsonl']]}
print(json.dumps([probe(k) for k in ('lost-parts-with-positive-received','wrong-final-content')],indent=2))
