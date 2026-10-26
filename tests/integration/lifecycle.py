#!/usr/bin/env python3
"""Real app disable, explicit uninstall, retained events and fresh reinstall."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import stat

root=Path(sys.argv[1]).resolve()
repo=Path(__file__).resolve().parents[2]
php=os.environ.get('DCB_PHP','php')
if not (root.parent/'.dcb-disposable').is_file():raise SystemExit('Disposable marker required')
os.chdir(root)
def run(*args):
    r=subprocess.run([php,str(root/'occ'),*args],capture_output=True,text=True)
    if r.returncode:raise RuntimeError(' '.join(args)+' failed: '+r.stdout+r.stderr)
    return r.stdout
def probe(action='probe'):
    r=subprocess.run([php,str(Path(__file__).with_name('lifecycle-fixture.php')),str(root),action],capture_output=True,text=True,check=True)
    return json.loads([s[9:] for s in r.stdout.splitlines() if s.startswith('DCB_JSON:')][-1])
def install():
    dest=root/'custom_apps/deckcalendarbridge';dest.mkdir()
    for folder in ['appinfo','lib','config','docs','templates','js','css','packaging','bin']:shutil.copytree(repo/folder,dest/folder)
    for file in ['README.md','LICENSE.md','CHANGELOG.md','CONTRIBUTING.md','SECURITY.md','AI_USAGE.md']:shutil.copy2(repo/file,dest/file)
    run('app:enable','deckcalendarbridge')
def check_removed(before,keep):
    after=probe()
    assert not any(after['tables'].values()),after['tables']
    assert after['config']=={} and after['migrations']==0 and after['jobs']==[],after
    assert not (root/'custom_apps/deckcalendarbridge').exists()
    assert after['labels']==before['labels'],'Uninstall changed Deck trigger tags'
    active_before={str(o['id']) for o in before['objects'] if o['deleted_at'] is None}
    active_after={str(o['id']) for o in after['objects'] if o['deleted_at'] is None}
    if keep:assert active_after==active_before,'Keep-events removed appointments'
    else:
        assert any(o['uid']=='unrelated-worker@example.invalid' and o['deleted_at'] is None for o in after['objects']),'Unrelated calendar object removed'
        assert active_after==active_before-set(before['managed_original_ids']),'Uninstall deleted an unrelated/retained object or left a managed original'
    print('PASS LIFECYCLE uninstall '+('keeps all appointments' if keep else 'deletes verified originals and preserves unrelated appointment')+'; app files, jobs, tables, migrations and config removed; Deck tags unchanged',flush=True)

doctor=json.loads(run('deckcalendarbridge:doctor'))
assert doctor['ok'],doctor
expected=os.environ.get('DCB_EXPECT_USER')
if expected:assert doctor['process_user']==expected,doctor
print('PASS LIFECYCLE doctor validates actual process/config owner and filesystem/cache access ('+doctor['process_user']+')',flush=True)
bad_home=subprocess.run([php,str(root/'occ'),'deckcalendarbridge:doctor'],env=dict(os.environ,HOME=str(root/'nonexistent-home')),capture_output=True,text=True)
home_report=json.loads(bad_home.stdout)
assert bad_home.returncode!=0 and home_report['checks']['home_directory_readable'] is False,'Doctor missed an invalid HOME'
print('PASS LIFECYCLE doctor rejects an unreadable or missing HOME',flush=True)
saved_rules=run('config:app:get','deckcalendarbridge','rules_snapshot_v1').strip()
try:
    run('config:app:set','deckcalendarbridge','rules_snapshot_v1','--value={"invalid":"rules"}')
    invalid=subprocess.run([php,str(root/'occ'),'deckcalendarbridge:doctor'],capture_output=True,text=True)
    invalid_report=json.loads(invalid.stdout)
    assert invalid.returncode!=0 and invalid_report['checks']['rules_valid'] is False,'Doctor missed invalid stored rules'
    print('PASS LIFECYCLE doctor rejects invalid stored rule configuration',flush=True)
finally:
    run('config:app:set','deckcalendarbridge','rules_snapshot_v1','--value='+saved_rules)
if os.geteuid()!=0:
    sequence=Path(doctor['id_generation']['snowflake_directory'])
    if sequence.is_dir():
        mode=stat.S_IMODE(sequence.stat().st_mode)
        try:
            sequence.chmod(0o500)
            failed=subprocess.run([php,str(root/'occ'),'deckcalendarbridge:doctor'],capture_output=True,text=True)
            diagnosis=json.loads(failed.stdout)
            assert failed.returncode!=0 and diagnosis['checks']['nextcloud_id_generation_works'] is False,'Doctor missed an unwritable core ID sequence'
            assert diagnosis['id_generation']['snowflake_directory']==str(sequence)
            print('PASS LIFECYCLE doctor diagnoses an unwritable core Snowflake directory under real web user',flush=True)
        finally:sequence.chmod(mode)
probe('unrelated')
before=probe();namespace=before['config']['runtime_namespace']
run('app:disable','deckcalendarbridge')
disabled=probe()
assert all(disabled['tables'].values()) and disabled['objects']==before['objects'] and disabled['labels']==before['labels'],'Disable deleted data'
run('app:enable','deckcalendarbridge')
assert probe()['config']['runtime_namespace']==namespace,'Pause rotated install identity'
print('PASS LIFECYCLE ordinary disable/re-enable preserves appointments, Deck tags, metadata and install identity',flush=True)
before=probe()
run('deckcalendarbridge:uninstall')
after_preview=probe()
assert before==after_preview,'Uninstall preview wrote state'
print('PASS LIFECYCLE uninstall preview is read-only',flush=True)
holder=subprocess.Popen([php,str(Path(__file__).with_name('worker-fixture.php')),str(root),'hold-lock',json.dumps({'id':int(before['labels'][0]['card_id'])})],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
try:
    if holder.stdout.readline().strip()!='HELD':raise RuntimeError('Uninstall lock fixture failed')
    blocked=subprocess.run([php,str(root/'occ'),'deckcalendarbridge:uninstall','--execute'],capture_output=True,text=True)
    assert blocked.returncode!=0,'Uninstall ignored an active card owner'
    deferred=probe()
    assert all(deferred['tables'].values()) and (root/'custom_apps/deckcalendarbridge/appinfo/info.xml').is_file(),'Failed cleanup purged relation metadata'
    assert deferred['config'].get('uninstall_in_progress')=='yes' and deferred['config'].get('uninstall_rules_backup'),'Failed cleanup lost recoverable configuration'
    print('PASS LIFECYCLE busy uninstall stops before metadata/file removal and retains recovery rules',flush=True)
    holder.communicate('release\n',timeout=5)
finally:
    if holder.poll() is None:holder.kill();holder.wait()
run('deckcalendarbridge:uninstall','--execute')
check_removed(before,False)
install()
new=probe()
assert new['config']['runtime_namespace']!=namespace,'Reinstall reused old cache namespace'
assert all(new['tables'].values()) and new['migrations']>0
assert new['config'].get('relations_v1_enabled','no')=='no','Reinstall enabled writes implicitly'
print('PASS LIFECYCLE clean reinstall creates fresh install/lock identity and starts disabled',flush=True)
subprocess.run(['python3',str(Path(__file__).with_name('worker.py')),str(root)],check=True)
before=probe()
run('deckcalendarbridge:uninstall','--keep-events','--execute')
check_removed(before,True)
print('All lifecycle scenarios passed.',flush=True)
