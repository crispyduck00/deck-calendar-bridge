#!/usr/bin/env python3
"""Offline tests for cleanup scope and a history-free, nonpublishing public export."""
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[1]
def load(name):
    spec=importlib.util.spec_from_file_location(name,ROOT/'tools'/f'{name}.py')
    module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module);return module
cleanup=load('github_cleanup');privacy=load('check_public_tree')

class MaintenanceTests(unittest.TestCase):
    def test_cleanup_keeps_default_protected_open_and_stable(self):
        source='example/source'
        snapshot={'repository':{'default_branch':'main','full_name':source},
          'branches':[{'name':n,'commit':{'sha':n},'protected':n=='feature/protected'} for n in
                      ['main','feature/old','release/0.3','rewrite/old','feature/open','feature/protected','personal-work']],
          'pulls':[{'state':'open','head':{'ref':'feature/open','repo':{'full_name':source}}}],
          'tags':[{'ref':'refs/tags/'+n} for n in ['v0.4.3','v1.0.0','v2.1.0']],
          'releases':[{'tag_name':n} for n in ['v0.4.3','v1.0.0','v2.1.0']]}
        branches,tags,releases=cleanup.selection(snapshot)
        self.assertEqual({b['name'] for b in branches},{'feature/old','release/0.3','rewrite/old'})
        self.assertEqual([t['ref'] for t in tags],['refs/tags/v0.4.3'])
        self.assertEqual([r['tag_name'] for r in releases],['v0.4.3'])

    def test_privacy_findings_do_not_echo_values(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory)
            (root/'text').write_text('gh'+'p_'+'a'*36+'\ninternal-example\n')
            findings=privacy.inspect(root,['text'],['internal-example'])
            self.assertEqual({x[2] for x in findings},{'credential-pattern','operator-denied-term'})
            self.assertNotIn('internal-example',str(findings))
    @unittest.skipUnless(os.name=='posix','POSIX symlink test; Windows junction refusal has a native publication test')
    def test_privacy_rejects_symbolic_links(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory)
            (root/'text').write_text('Fixture')
            (root/'link').symlink_to(root/'text')
            self.assertEqual(privacy.inspect(root,['link'])[0][2],'symbolic-link')

    @unittest.skipUnless(os.name=='posix','POSIX shell export; use the native PowerShell publication tests on Windows')
    def test_public_dry_run_exports_only_current_tree(self):
        real_git=shutil.which('git')
        with tempfile.TemporaryDirectory() as directory:
            base=Path(directory); source=base/'source';source.mkdir()
            def git(*args):
                return subprocess.check_output([real_git,'-C',str(source),*args],stderr=subprocess.DEVNULL,text=True).strip()
            git('init','-b','main');git('config','user.name','Project contributors');git('config','user.email','contributors@example.invalid');git('config','commit.gpgsign','false')
            (source/'old-internal.txt').write_text('Earlier content must remain outside the export')
            git('add','.');git('commit','-m','Earlier private history')
            (source/'old-internal.txt').unlink()
            (source/'appinfo').mkdir();(source/'appinfo/info.xml').write_text('<info><id>deckcalendarbridge</id><version>1.0.0</version></info>')
            (source/'tools').mkdir();shutil.copy2(ROOT/'tools/check_public_tree.py',source/'tools/check_public_tree.py')
            (source/'README.md').write_text('Project: https://github.com/example/source/releases')
            git('add','-A');git('commit','-m','Reviewed release');git('tag','v1.0.0')
            bin_dir=base/'bin';bin_dir.mkdir()
            (bin_dir/'git').write_text('#!/usr/bin/env python3\nimport os,sys\na=sys.argv[1:]\na=[os.environ["FIXTURE_SOURCE"] if x=="https://github.com/example/source.git" else x for x in a]\nassert not any(x.startswith("https://") for x in a),"Unexpected network operation"\nos.execv(os.environ["REAL_GIT"],[os.environ["REAL_GIT"],*a])\n')
            (bin_dir/'gh').write_text('#!/usr/bin/env python3\nimport sys,os,json\nif sys.argv[1:]==["auth","status"]:pass\nelif sys.argv[1:]==["api","repos/example/source/git/ref/tags/v1.0.0"]:print(json.dumps({"object":{"type":"commit","sha":os.environ["TAG_SHA"]}}))\nelse:raise RuntimeError("Dry-run attempted a mutation")\n')
            for path in bin_dir.iterdir():path.chmod(0o755)
            env=dict(os.environ,PATH=str(bin_dir)+os.pathsep+os.environ['PATH'],FIXTURE_SOURCE=str(source),REAL_GIT=real_git,TMPDIR=str(base),TAG_SHA=git('rev-parse','HEAD'))
            result=subprocess.run(['sh',str(ROOT/'tools/create_public_copy.sh'),'example/source','example/public','v1.0.0'],env=env,capture_output=True,text=True)
            self.assertEqual(result.returncode,0,result.stdout+result.stderr)
            export=next(base.glob('deck-calendar-public.*/public'))
            self.assertFalse((export/'.git').exists());self.assertFalse((export/'old-internal.txt').exists())
            self.assertIn('https://github.com/example/public/releases',(export/'README.md').read_text())
            self.assertIn('Dry-run:',result.stdout)
            self.assertEqual(git('rev-list','--count','HEAD'),'2')

if __name__=='__main__':unittest.main()
