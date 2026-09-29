import json, pathlib, subprocess, sys, tempfile
root = pathlib.Path.cwd()
baseline = '15170d5c02cb4bfe49fe587cb00db49faada5a50'
with tempfile.TemporaryDirectory(prefix='vaulter-r04-regression-') as tmp:
    snapshot = pathlib.Path(tmp)
    for name in ('p3_workload.py', 'p3_profile.py', 'p3_explain.py', 'p3_trace_report.py'):
        path = snapshot / 'load/gates' / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(subprocess.check_output(['git', 'show', f'{baseline}:load/gates/{name}']))
    script = snapshot / 'load/scripts/p3-capture-workload.sh'
    script.parent.mkdir(parents=True)
    script.write_bytes(subprocess.check_output(['git', 'show', f'{baseline}:load/scripts/p3-capture-workload.sh']))
    for name in ('test_r04_capture.py', 'test_r04_publication.py', 'test_r04_trace_validation.py', 'test_p3_trace_report.py', 'test_p3_explain.py'):
        (snapshot / 'load/gates' / name).write_bytes((root / 'load/gates' / name).read_bytes())
    cases = ['test_p3_explain.P3ExplainHelpersTest.test_capture_and_index_default_to_selected_run_explain_directory', 'test_p3_trace_report.P3TraceReportTest.test_unexecuted_wrong_identity_and_pending_plans_are_rejected', 'test_r04_publication.R04PublicationTest.test_missing_manifest_and_artifact_identity_are_rejected', 'test_r04_publication.R04PublicationTest.test_competing_publishers_accept_exactly_one_snapshot', 'test_r04_capture.R04CaptureTest.test_failed_work_never_publishes', 'test_r04_capture.R04CaptureTest.test_success_joins_warm_writer_and_uses_fresh_publication']
    result = subprocess.run([sys.executable, '-B', '-m', 'unittest', *cases], cwd=snapshot / 'load/gates', capture_output=True, text=True)
    print(json.dumps({'baseline': baseline, 'exit': result.returncode, 'interpretation': 'Expected regression rejection on pre-fix source; fake services only.'}))
    print(result.stdout)
    print(result.stderr)
    if result.returncode == 0:
        raise SystemExit('Unexpected baseline pass')
