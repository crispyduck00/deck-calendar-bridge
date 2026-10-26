#!/usr/bin/env python3
"""Exercise a real long-lived occ worker; mutations occur in other processes."""
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import time

root=Path(sys.argv[1]).resolve()
if not (root.parent/'.dcb-disposable').is_file(): raise SystemExit('Disposable marker required')
os.chdir(root)
php=os.environ.get('DCB_PHP','php')
fixture=Path(__file__).with_name('worker-fixture.php')
def call(action,data=None):
    r=subprocess.run([php,str(fixture),str(root),action,json.dumps(data or {})],capture_output=True,text=True)
    if r.returncode:raise RuntimeError(action+' failed: '+r.stdout+r.stderr)
    lines=[x[9:] for x in r.stdout.splitlines() if x.startswith('DCB_JSON:')]
    if not lines: raise RuntimeError(r.stdout+r.stderr)
    return json.loads(lines[-1])
def core_probe():
    r=subprocess.run([php,str(Path(__file__).with_name('lifecycle-fixture.php')),str(root)],capture_output=True,text=True,check=True)
    return json.loads([x[9:] for x in r.stdout.splitlines() if x.startswith('DCB_JSON:')][-1])
def wait_for(name,check,seconds=18):
    end=time.monotonic()+seconds; last=None
    while time.monotonic()<end:
        if worker.poll() is not None: raise RuntimeError('Worker exited: '+logpath.read_text()[-4000:])
        last=call('state',{'id':card})
        if check(last): print('PASS WORKER '+name,flush=True); return last
        time.sleep(.35)
    raise RuntimeError(name+' timed out: '+json.dumps(last)+'\n'+logpath.read_text()[-4000:])

setup=call('setup')
# Create a qualifying source while event intake is disabled. Only the native
# timed scan may discover it after re-enable; do not call Scanner or Runner.
subprocess.run([php,str(root/'occ'),'config:app:set','deckcalendarbridge','relations_v1_enabled','--value=no'],check=True,capture_output=True)
recovery_card=call('create',setup)['id']
unobserved=call('state',{'id':recovery_card})
if unobserved['relations'] or unobserved['queue'] is not None:raise RuntimeError('Recovery fixture accidentally queued its card')
subprocess.run([php,str(root/'occ'),'config:app:set','deckcalendarbridge','relations_v1_enabled','--value=yes'],check=True,capture_output=True)
with tempfile.TemporaryDirectory(prefix='dcb-worker-') as directory:
    logpath=Path(directory)/'worker.log'
    with logpath.open('w') as log:
        command=[php,str(root/'occ'),'background-job:worker','--interval','1','--stop_after','120s','-v','OCA\\DeckCalendarBridge\\BackgroundJob\\ReconcileCardJob','OCA\\DeckCalendarBridge\\BackgroundJob\\SyncScanJob']
        worker=subprocess.Popen(command,stdout=log,stderr=log,cwd=root)
        second=None
        try:
            time.sleep(.6)
            card=recovery_card
            wait_for('native timed scan recovers a card without an observed event or manual sync',lambda s:len(s['relations'])==2 and all(r['remote']=='present' for r in s['relations'].values()))
            card=call('create',setup)['id']
            state=wait_for('Deck event creates both original calendars without manual sync',lambda s:len(s['relations'])==2 and all(r['remote']=='present' for r in s['relations'].values()))
            second=subprocess.Popen(command,stdout=log,stderr=log,cwd=root)
            initial=state['card']['start']
            call('shift',{'id':card,'ruleId':setup['rules'][0]['id']})
            wait_for('calendar event updates card and other calendar in unauthenticated CLI worker',lambda s:s['card']['start']==initial+900 and all(r.get('document',{}).get('time',{}).get('start')==initial+900 for r in s['relations'].values()))
            changed=setup['rules']
            for r in changed:
                r['presentation'].update(titlePrefixMode='custom',titlePrefix='Changed',titleSeparator=' - ')
            call('rules',{'rules':changed})
            call('edit',{'id':card,'title':'Fresh configuration'})
            wait_for('running worker sees rules changed by another process',lambda s:all(r.get('document',{}).get('title')=='Changed - Fresh configuration' for r in s['relations'].values()))
            legacy=subprocess.Popen([php,str(fixture),str(root),'hold-lock',json.dumps({'id':card,'legacy':True})],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
            try:
                if legacy.stdout.readline().strip()!='HELD':raise RuntimeError('Legacy lock fixture failed')
                call('edit',{'id':card,'title':'Fresh namespace'})
                wait_for('old v1 Redis lock cannot block the new installation',lambda s:all(r.get('document',{}).get('title')=='Changed - Fresh namespace' for r in s['relations'].values()))
                legacy.communicate('release\n',timeout=5)
            finally:
                if legacy.poll() is None:legacy.kill();legacy.wait()
            holder=subprocess.Popen([php,str(fixture),str(root),'hold-lock',json.dumps({'id':card})],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
            try:
                if holder.stdout.readline().strip()!='HELD':raise RuntimeError('Lock fixture failed')
                call('edit',{'id':card,'title':'After lock release'})
                other=call('create',setup)['id']
                manual=subprocess.run([php,str(root/'occ'),'deckcalendarbridge:sync'],capture_output=True,text=True)
                if manual.returncode:raise RuntimeError('Manual sync aborted during worker contention: '+manual.stdout+manual.stderr)
                other_state=call('state',{'id':other})
                if len(other_state['relations'])!=2:raise RuntimeError('Busy card starved independent card')
                print('PASS WORKER two workers plus manual sync preserve independent-card progress',flush=True)
                time.sleep(2.5)
                busy=call('state',{'id':card})
                if busy['queue'] is None:raise RuntimeError('Busy worker lost durable pending work')
                holder.communicate('release\n',timeout=5)
            finally:
                if holder.poll() is None:holder.kill();holder.wait()
            wait_for('busy event job reschedules itself and runs after release without cron',lambda s:all(r.get('document',{}).get('title')=='Changed - After lock release' for r in s['relations'].values()) and s['queue'] is None)
            subprocess.run([php,str(root/'occ'),'config:app:set','deckcalendarbridge','relations_v1_enabled','--value=no'],check=True,capture_output=True)
            call('edit',{'id':card,'title':'Paused'})
            call('queue',{'id':card})
            time.sleep(2)
            paused=call('state',{'id':card})
            if any(r.get('document',{}).get('title')!='Changed - After lock release' for r in paused['relations'].values()):raise RuntimeError('Running worker ignored disabled flag')
            print('PASS WORKER running process observes external disable immediately for new jobs',flush=True)
            subprocess.run([php,str(root/'occ'),'config:app:set','deckcalendarbridge','relations_v1_enabled','--value=yes'],check=True,capture_output=True)
            call('edit',{'id':card,'title':'Resumed'})
            resumed=wait_for('running process observes re-enable and retains original relationships',lambda s:all(r.get('document',{}).get('title')=='Changed - Resumed' for r in s['relations'].values()))
            uids={r['uid'] for r in resumed['relations'].values()}
            originals={o['uid']:o['calendardata'] for o in core_probe()['objects'] if o['uid'] in uids}
            held=subprocess.Popen([php,str(fixture),str(root),'hold-lock',json.dumps({'id':card})],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
            try:
                if held.stdout.readline().strip()!='HELD':raise RuntimeError('Disable lock fixture failed')
                call('edit',{'id':card,'title':'During app disable'})
                time.sleep(1.3)
                subprocess.run([php,str(root/'occ'),'app:disable','deckcalendarbridge'],check=True,capture_output=True)
                held.communicate('release\n',timeout=5)
            finally:
                if held.poll() is None:held.kill();held.wait()
            time.sleep(2.5)
            disabled=core_probe()
            assert all(disabled['tables'].values()) and originals=={o['uid']:o['calendardata'] for o in disabled['objects'] if o['uid'] in uids},'Live worker wrote through app disable'
            print('PASS WORKER app disable stops already queued work without deleting bridge data',flush=True)
            subprocess.run([php,str(root/'occ'),'app:enable','deckcalendarbridge'],check=True,capture_output=True)
            call('edit',{'id':card,'title':'After app enable'})
            wait_for('existing workers resume safely after app re-enable',lambda s:all(r.get('document',{}).get('title')=='Changed - After app enable' for r in s['relations'].values()))
            call('calendar-delete',{'id':card,'ruleId':setup['rules'][0]['id']})
            wait_for('calendar delete event removes exact trigger through running worker',lambda s:setup['tags'][0] not in [int(x) for x in s['card']['labels']] and len([r for r in s['relations'].values() if r['state']=='active'])==1)
        finally:
            for process in [worker,second]:
                if process is None:continue
                process.terminate()
                try:process.wait(timeout=10)
                except subprocess.TimeoutExpired:process.kill();process.wait()
            if worker.returncode not in (0,-15):print(logpath.read_text()[-3500:],flush=True)
