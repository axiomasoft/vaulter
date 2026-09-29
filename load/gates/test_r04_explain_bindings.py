"""Historical P3 rejection shapes must receive typed, noncolliding bindings."""

import importlib.util
import sys
import unittest
from pathlib import Path

GATES = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('p3_explain', GATES / 'p3_explain.py')
explain = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = explain
spec.loader.exec_module(explain)

SAMPLES = {
    'grant_parent_id': 12, 'storage_id': 1, 'actor_id': 900001,
    'node_id': 1, 'actor_type': 'load-actor', 'target_type': 'vaulter-node',
    'verb': 'viewed', 'upload_uuid': '00000000-0000-4000-8000-000000000001',
    'insert_upload_uuid': '00000000-0000-4000-8000-000000000002',
    'file_id': 1, 'blob_id': 1, 'next_version_no': 42, 'max_depth': 50,
}


class R04BindingTest(unittest.TestCase):
    def test_all_rows_of_batch_activity_insert_use_column_types(self):
        columns = '"actor_id", "actor_type", "context", "created_at"'
        query = ('insert into "v_activity" (' + columns + ') values '
                 '($1, $2, $3, $4), ($5, $6, $7, $8)')
        args = explain.infer_execute_exprs(query, SAMPLES)
        self.assertEqual(args[0:4], args[4:8])
        self.assertEqual(args[6], "'{}'::jsonb")
        self.assertNotIn('NULL::bigint', args)

    def test_file_version_avoids_existing_unique_pair(self):
        query = 'insert into "v_file_versions" ("file_id", "version_no") values ($1, $2)'
        self.assertEqual(explain.infer_execute_exprs(query, SAMPLES), ['1::bigint', '42::int'])

    def test_upload_insert_and_expiry_predicate_are_typed(self):
        query = 'insert into "v_upload_sessions" ("upload_id", "metadata") values ($1, $2)'
        self.assertEqual(explain.infer_execute_exprs(query, SAMPLES), [
            "'00000000-0000-4000-8000-000000000002'::uuid", "'{}'::json",
        ])
        select = 'select * from "v_node_grants" where "expires_at" > $1'
        self.assertEqual(explain.infer_execute_exprs(select, SAMPLES), ['NOW()::timestamp'])

    def test_upsert_update_bindings_follow_column_types(self):
        query = ('insert into "v_upload_sessions" ("upload_id", "metadata") values ($1, $2) '
                 'on conflict ("upload_id") do update set "metadata" = $3, "expires_at" = $4')
        self.assertEqual(explain.infer_execute_exprs(query, SAMPLES), [
            "'00000000-0000-4000-8000-000000000002'::uuid",
            "'{}'::json", "'{}'::json", 'NOW()::timestamp',
        ])


if __name__ == '__main__':
    unittest.main()
