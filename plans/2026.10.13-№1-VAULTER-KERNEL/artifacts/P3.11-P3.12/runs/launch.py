import json,subprocess,sys,os
lane=sys.argv[1]; A=sys.argv[2]
env=dict(os.environ,SKM_ORCH_ROOT="/home/vostrikov/projects/packages/swissknifeman/packages/orch")
P=os.path.expanduser("~/.claude/plugins/cache/swissknifeman/task/0.28.3/scripts/plan.py")
spec=json.loads(subprocess.check_output(["python3",P,"bridge","orch","launch","reviewer","grok","--model","grok-4.7","--effort","high"],env=env))
with open(f"{A}/briefs/{lane}.md") as i, open(f"{A}/review-{lane}.jsonl","w") as o:
    subprocess.run(spec["argv"],stdin=i,stdout=o,stderr=subprocess.STDOUT,timeout=spec["timeout_s"],env=env)
