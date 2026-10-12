from common import *
base=Path(sys.argv[1]);proof=verify_generation(base)
print(json.dumps({'completed_generation_result':proof['result'],'completed_at':proof['completed_at'],'source_members':len(proof['source_hashes']),'golden_members':len(proof['golden_hashes']),'generation_complete_before_first_lint':True}))
