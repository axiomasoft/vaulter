-- Tests the proposed PostgreSQL DDL. The application-level writer is tested separately in phase 1.
BEGIN;
DO $test$
DECLARE
    drive_key uuid := uuidv7();
    folder uuid := uuidv7();
    n1 uuid := uuidv7(); n2 uuid := uuidv7();
    doc1 uuid := uuidv7(); doc2 uuid := uuidv7();
    rev1 uuid := uuidv7();
    value jsonb := '{"type":"doc","content":[{"type":"paragraph","attrs":{"blockId":"p42"}}]}';
BEGIN
    INSERT INTO v_drives(id,tenant_id,owner_id,owner_type,name) VALUES (drive_key,1,42,'user','Spec');
    INSERT INTO v_nodes(id,tenant_id,drive_id,kind,name,name_key) VALUES (folder,1,drive_key,'folder','Root','root');
    UPDATE v_drives SET root_node_id=folder WHERE id=drive_key;
    INSERT INTO v_nodes(id,tenant_id,drive_id,parent_id,kind,name,name_key) VALUES
      (n1,1,drive_key,folder,'document','Terms','terms'), (n2,1,drive_key,folder,'document','Book','book');
    INSERT INTO v_documents(id,tenant_id,node_id,type,schema_version) VALUES
      (doc1,1,n1,'vaulter/rich-text',1), (doc2,1,n2,'vaulter/spreadsheet',1);
    INSERT INTO v_document_payloads(document_id,content,content_hash,content_size)
      SELECT doc1,value,encode(sha256(convert_to(value::text,'UTF8')),'hex'),octet_length(value::text);
    INSERT INTO v_document_payloads(document_id) VALUES (doc2);
    INSERT INTO v_document_revisions(id,tenant_id,document_id,number,type,schema_version)
      VALUES (rev1,1,doc1,1,'vaulter/rich-text',1);
    INSERT INTO v_document_revision_payloads(revision_id,content,content_hash,content_size)
      SELECT rev1,content,content_hash,content_size FROM v_document_payloads WHERE document_id=doc1;
    UPDATE v_documents SET current_revision_id=rev1 WHERE id=doc1;
    IF (SELECT write_mode FROM v_documents WHERE id=doc1) <> 'snapshot' THEN
        RAISE EXCEPTION 'Unexpected mode of new document';
    END IF;
    BEGIN
        UPDATE v_documents SET write_mode='invalid' WHERE id=doc1;
        RAISE EXCEPTION 'Unknown write mode accepted';
    EXCEPTION WHEN check_violation THEN NULL;
    END;
    BEGIN
        UPDATE v_documents SET current_revision_id=rev1 WHERE id=doc2;
        RAISE EXCEPTION 'Cross-document revision accepted';
    EXCEPTION WHEN foreign_key_violation THEN NULL;
    END;
    BEGIN
        INSERT INTO v_document_payloads(document_id) VALUES (doc1);
        RAISE EXCEPTION 'Duplicate working payload accepted';
    EXCEPTION WHEN unique_violation THEN NULL;
    END;
    BEGIN
        UPDATE v_document_payloads SET content_hash=NULL WHERE document_id=doc1;
        RAISE EXCEPTION 'Partial payload accepted';
    EXCEPTION WHEN check_violation THEN NULL;
    END;
    BEGIN
        INSERT INTO v_document_sync_states(document_id,pending_generation) VALUES (doc1,3);
        RAISE EXCEPTION 'Partial pending marker accepted';
    EXCEPTION WHEN check_violation THEN NULL;
    END;
    INSERT INTO v_document_sync_states(document_id,pending_protocol,pending_state_format,
        pending_schema_version,pending_generation,pending_through_seq)
      VALUES (doc1,'yjs','update',1,3,41);
    BEGIN
        UPDATE v_document_sync_states SET collab_protocol='yjs',collab_state_format='update',
             collab_state=decode('01ff','hex'),collab_state_size=1 WHERE document_id=doc1;
        RAISE EXCEPTION 'Invalid binary length accepted';
    EXCEPTION WHEN check_violation THEN NULL;
    END;
    BEGIN
        UPDATE v_documents SET state_version=1 WHERE id=doc1;
        UPDATE v_document_payloads SET content_size=-1 WHERE document_id=doc1;
        RAISE EXCEPTION 'Injected write fault did not fire';
    EXCEPTION WHEN check_violation THEN NULL;
    END;
    IF (SELECT state_version FROM v_documents WHERE id=doc1) <> 0 THEN
        RAISE EXCEPTION 'Head survived an aborted payload write';
    END IF;
    UPDATE v_documents SET current_revision_id=NULL WHERE id=doc1;
    DELETE FROM v_nodes WHERE id=n1;
    IF EXISTS (SELECT 1 FROM v_document_payloads WHERE document_id=doc1)
       OR EXISTS (SELECT 1 FROM v_document_sync_states WHERE document_id=doc1)
       OR EXISTS (SELECT 1 FROM v_document_revision_payloads WHERE revision_id=rev1)
       OR NOT EXISTS (SELECT 1 FROM v_document_payloads WHERE document_id=doc2) THEN
        RAISE EXCEPTION 'Unexpected result of deleting one resource';
    END IF;
END $test$;
ROLLBACK;
