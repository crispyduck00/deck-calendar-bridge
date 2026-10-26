#!/usr/bin/env python3
"""Validate generated systemd units, including paths with spaces and specifier characters."""
import os
from pathlib import Path
import pwd
import subprocess
import tempfile

if os.geteuid()==0:
    print('SKIP generated-unit positive cases require a non-root config owner; native CI runs them as its runner user')
    raise SystemExit(0)
repo=Path(__file__).resolve().parents[1]
php=os.environ.get('DCB_PHP','php')
for prefix in ['dcb-unit-','dcb unit %$ ']:
    with tempfile.TemporaryDirectory(prefix=prefix) as directory:
        root=Path(directory)/'nextcloud';(root/'config').mkdir(parents=True)
        (root/'config/config.php').write_text('<?php\n')
        (root/'occ').write_text('<?php\n')
        result=subprocess.run([php,str(repo/'bin/render-worker.php'),str(root)],capture_output=True,text=True)
        assert result.returncode==0,result.stderr
        unit=Path(directory)/'deckcalendarbridge-test.service';unit.write_text(result.stdout)
        assert 'User='+pwd.getpwuid(os.geteuid()).pw_name+'\n' in result.stdout
        assert 'ReconcileCardJob' in result.stdout and 'SyncScanJob' in result.stdout
        checked=subprocess.run(['systemd-analyze','verify',str(unit)],capture_output=True,text=True)
        assert checked.returncode==0,checked.stdout+checked.stderr
print('PASS generated worker units use the actual owner and parse for ordinary and spaced/specifier paths')
