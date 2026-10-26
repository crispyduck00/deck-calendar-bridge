#!/usr/bin/env python3
"""Windows-native publication tests. Git is real; every network command is mocked."""
import hashlib
import io
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[1]
SHELL = os.environ.get('DCB_POWERSHELL', 'pwsh')
MOCK = r'''
import hashlib,json,os,pathlib,subprocess,sys,zipfile
kind=sys.argv[1];a=sys.argv[2:]
with open(os.environ['DCB_TRACE'],'a',encoding='utf-8') as f:f.write(json.dumps([kind,a])+'\n')
def head():return subprocess.check_output([os.environ['DCB_REAL_GIT'],'rev-parse','HEAD'],text=True).strip()
if kind=='git':
    if 'push' in a:
        assert a[-3:]==['--set-upstream','origin','main'],a
        sys.exit(0)
    assert not any(x in a for x in ['clone','fetch','pull','ls-remote']),a
    sys.exit(subprocess.call([os.environ['DCB_REAL_GIT'],*a]))
if a[:2]==['auth','status']:pass
elif a[:2]==['repo','create']:
    assert a[2]=='example/public' and '--public' in a,a
    if os.environ.get('DCB_FAIL')=='create':sys.exit(37)
elif a[:2]==['repo','edit']:assert a[2:]==['example/public','--default-branch','main'],a
elif a[:2]==['run','list']:
    assert '--workflow' not in a,'Do not resolve a not-yet-indexed workflow on the first push'
    seen=sum(1 for line in pathlib.Path(os.environ['DCB_TRACE']).read_text(encoding='utf-8').splitlines() if json.loads(line)[1][:2]==['run','list'])
    print(json.dumps([] if seen==1 else [{'databaseId':123,'headSha':head(),'workflowName':'Relations engine and native NC35 integration'}]))
elif a[:2]==['run','watch']:
    assert a[2:] == ['123','--repo','example/public','--exit-status'],a
    if os.environ.get('DCB_FAIL')=='ci':sys.exit(38)
elif a[:1]==['api'] and a[1]=='repos/example/public/git/ref/tags/v1.1.1':
    print(json.dumps({'object':{'type':'commit','sha':'0'*40 if os.environ.get('DCB_FAIL')=='tag' else head()}}))
elif a[:1]==['api'] and a[1]=='repos/example/public/releases/tags/v1.1.1':
    print(json.dumps({'draft':False,'prerelease':False,'html_url':'https://github.com/example/public/releases/tag/v1.1.1',
      'assets':[{'name':'deckcalendarbridge-nc35-1.1.1.zip'},{'name':'deckcalendarbridge-nc35-1.1.1.zip.sha256'}]}))
elif a[:2]==['release','download']:
    dest=pathlib.Path(a[a.index('--dir')+1]);name='deckcalendarbridge-nc35-1.1.1.zip'
    with zipfile.ZipFile(dest/name,'w') as z:z.writestr('deckcalendarbridge/BUILD_COMMIT',head()+'\n')
    digest=hashlib.sha256((dest/name).read_bytes()).hexdigest()
    if os.environ.get('DCB_FAIL')=='checksum':digest='0'*64
    (dest/(name+'.sha256')).write_text(digest+'  '+name+'\n',encoding='utf-8')
else:raise RuntimeError('Unexpected network/mutation command: '+repr(a))
'''


@unittest.skipUnless(os.name == 'nt', 'Actual Windows PowerShell tests run in the Windows CI jobs')
class PublicationTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='dcb publication ')
        self.base = Path(self.temp.name)
        self.source = self.base/'source'
        self.source.mkdir()
        self.real_git = shutil.which('git')
        self.shell = shutil.which(SHELL)
        self.assertIsNotNone(self.shell, SHELL+' must be installed')
        self.assertIsNotNone(self.real_git)
        docs = ['README.md','LICENSE.md','CHANGELOG.md','CONTRIBUTING.md','SECURITY.md','AI_USAGE.md',
                'docs/INSTALLATION.md','docs/UNINSTALLATION.md','docs/DEVELOPMENT.md',
                '.github/workflows/ci.yml','tests/sync-runtime.php','appinfo/info.xml']
        for name in docs:
            p = self.source/name
            p.parent.mkdir(parents=True,exist_ok=True)
            p.write_text('Fixture\n',encoding='utf-8')
        (self.source/'appinfo/info.xml').write_text('<info><id>deckcalendarbridge</id><version>1.1.1</version></info>',encoding='utf-8')
        (self.source/'README.md').write_text('Project https://github.com/example/source/releases\nPrüfung\n',encoding='utf-8')
        (self.source/'.gitattributes').write_text('* text=auto eol=lf\n',encoding='utf-8')
        self.work = self.base/'fresh output'
        self.trace = self.base/'commands.jsonl'
        bin_dir = self.base/'mock bin'
        bin_dir.mkdir()
        mock = bin_dir/'mock_cli.py'
        mock.write_text(MOCK,encoding='utf-8')
        for name in ['gh','git']:
            (bin_dir/(name+'.cmd')).write_text('@"'+sys.executable+'" "'+str(mock)+'" '+name+' %*\r\n',encoding='utf-8')
        self.env = dict(os.environ,PATH=str(bin_dir)+os.pathsep+os.environ['PATH'],DCB_REAL_GIT=self.real_git,
                        DCB_TRACE=str(self.trace),PYTHONUTF8='1',GH_HOST='github.com')
        self.env.pop('DCB_FAIL',None)
        self.source_repository = 'example/source'

    def tearDown(self):
        self.temp.cleanup()

    def invoke(self,*flags):
        args = [self.shell,'-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',
                str(ROOT/'tools/Publish-CleanRepository.ps1'),'-Repository','example/public',
                '-SourcePath',str(self.source),'-WorkDirectory',str(self.work)]
        if self.source_repository is not None:
            args += ['-SourceRepository',self.source_repository]
        args += list(flags)
        return subprocess.run(args,env=self.env,capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=90)

    def commands(self):
        return [json.loads(s) for s in self.trace.read_text(encoding='utf-8').splitlines()] if self.trace.exists() else []

    def test_preview_has_no_external_calls_and_preserves_source(self):
        r = self.invoke()
        self.assertEqual(r.returncode,0,r.stdout+r.stderr)
        self.assertEqual(self.commands(),[])
        stage = self.work/'repository'
        self.assertFalse((stage/'.git').exists())
        self.assertTrue((stage/'.github/workflows/ci.yml').is_file())
        self.assertIn('https://github.com/example/public/releases',(stage/'README.md').read_text(encoding='utf-8'))
        self.assertIn('Prüfung',(stage/'README.md').read_text(encoding='utf-8'))
        self.assertIn('example/source',(self.source/'README.md').read_text(encoding='utf-8'))
        plan = json.loads((self.work/'publication-plan.json').read_text(encoding='utf-8'))
        self.assertEqual(plan['version'],'1.1.1')
        for f in plan['files']:
            self.assertEqual(hashlib.sha256((stage/f['path']).read_bytes()).hexdigest(),f['sha256'])

    def test_rejects_git_metadata_before_any_network(self):
        (self.source/'.git').mkdir()
        r = self.invoke('-Apply')
        self.assertNotEqual(r.returncode,0)
        self.assertEqual(self.commands(),[])

    def test_complete_tracked_source_exports_on_windows(self):
        self.source = self.base/'complete source'
        self.source.mkdir()
        self.source_repository = None
        archive = subprocess.check_output([self.real_git,'-C',str(ROOT),'archive','--format=zip','HEAD'])
        with zipfile.ZipFile(io.BytesIO(archive)) as z:
            z.extractall(self.source)
        r = self.invoke()
        self.assertEqual(r.returncode,0,r.stdout+r.stderr)
        self.assertEqual(self.commands(),[])
        stage = self.work/'repository'
        self.assertFalse((stage/'.git').exists())
        self.assertTrue((stage/'lib/Sync/Reconciler.php').is_file())
        self.assertTrue((stage/'docs/WINDOWS_PUBLICATION.md').is_file())
        self.assertTrue((stage/'AI_USAGE.md').is_file())
        self.assertIn('https://github.com/example/public/releases',(stage/'README.md').read_text(encoding='utf-8'))
        self.assertEqual({p.relative_to(self.source).as_posix() for p in self.source.rglob('*') if p.is_file()},
                         {p.relative_to(stage).as_posix() for p in stage.rglob('*') if p.is_file()})

    def test_private_values_are_redacted(self):
        secret = 'private-example-for-review'
        (self.source/'README.md').write_text(secret,encoding='utf-8')
        terms = self.base/'private-terms.txt'
        terms.write_text(secret,encoding='utf-8')
        r = self.invoke('-Apply','-DenyTermsFile',str(terms))
        self.assertNotEqual(r.returncode,0)
        self.assertNotIn(secret,r.stdout+r.stderr)
        self.assertEqual(self.commands(),[])

    def test_junction_is_refused_without_traversal(self):
        outside = self.base/'outside'
        outside.mkdir()
        (outside/'private.txt').write_text('Do not copy',encoding='utf-8')
        subprocess.run(['cmd','/c','mklink','/J',str(self.source/'link'),str(outside)],check=True,capture_output=True)
        r = self.invoke('-Apply')
        self.assertNotEqual(r.returncode,0)
        self.assertEqual(self.commands(),[])

    def test_publish_checks_actual_git_history_and_release_receipt(self):
        r = self.invoke('-Apply','-WaitForRelease')
        self.assertEqual(r.returncode,0,r.stdout+r.stderr)
        stage = self.work/'repository'
        self.assertEqual(subprocess.check_output([self.real_git,'-C',str(stage),'rev-list','--count','HEAD'],text=True).strip(),'1')
        identity = subprocess.check_output([self.real_git,'-C',str(stage),'log','-1','--format=%an|%ae'],text=True).strip()
        self.assertEqual(identity,'Project contributors|contributors@example.invalid')
        receipt = json.loads((self.work/'publication-receipt.json').read_text(encoding='utf-8'))
        self.assertEqual(receipt['repository'],'example/public')
        self.assertEqual(receipt['commit'],subprocess.check_output([self.real_git,'-C',str(stage),'rev-parse','HEAD'],text=True).strip())
        self.assertTrue(any(cmd[:2]==['repo','create'] and '--public' in cmd for kind,cmd in self.commands() if kind=='gh'))

    def test_create_failure_stops_before_push(self):
        self.env['DCB_FAIL'] = 'create'
        r = self.invoke('-Apply','-WaitForRelease')
        self.assertNotEqual(r.returncode,0)
        self.assertIn('exit 37',r.stdout+r.stderr)
        self.assertTrue(any(cmd[:2]==['repo','create'] for kind,cmd in self.commands() if kind=='gh'))
        self.assertFalse(any('push' in cmd for kind,cmd in self.commands() if kind=='git'))
        self.assertFalse((self.work/'publication-receipt.json').exists())

    def test_ci_tag_and_checksum_failures_never_claim_a_release(self):
        for failure in ['ci','tag','checksum']:
            with self.subTest(failure=failure):
                self.work = self.base/('failed '+failure)
                self.env['DCB_FAIL'] = failure
                self.trace.unlink(missing_ok=True)
                r = self.invoke('-Apply','-WaitForRelease')
                self.assertNotEqual(r.returncode,0,r.stdout+r.stderr)
                self.assertFalse((self.work/'publication-receipt.json').exists())
                expected = {'ci':['run','watch'],'tag':['api','repos/example/public/git/ref/tags/v1.1.1'],
                            'checksum':['release','download']}[failure]
                self.assertTrue(any(cmd[:2]==expected for kind,cmd in self.commands() if kind=='gh'),self.commands())
                message = {'ci':'exit 38','tag':'Release tag does not point','checksum':'Release checksum mismatch'}[failure]
                self.assertIn(message,r.stdout+r.stderr)


if __name__ == '__main__':
    unittest.main()
