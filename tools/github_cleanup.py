#!/usr/bin/env python3
"""One-time repository cleanup after 1.0. Dry-run by default; backup before deletion."""
import argparse
import base64
import json
import os
from pathlib import Path
import re
import subprocess
import xml.etree.ElementTree as ET


def run(args,**kwargs):
    result=subprocess.run(args,capture_output=True,check=True,**kwargs)
    return result.stdout.decode()


def api(path,method='GET'):
    text=run(['gh','api','--method',method,path])
    return json.loads(text) if text.strip() else None


def pages(path,key=None):
    raw=json.loads(run(['gh','api','--paginate','--slurp',path]))
    return [item for page in raw for item in (page[key] if key else page)]


def git(*args):
    return run(['git','-c','credential.helper=','-c','credential.helper=!gh auth git-credential',*args])


def stable(tag):
    return bool(re.match(r'^v?[1-9][0-9]*\.',tag))


def selection(snapshot):
    default=snapshot['repository']['default_branch']
    open_heads={p['head']['ref'] for p in snapshot['pulls'] if p['state']=='open'
                and p['head'].get('repo') and p['head']['repo']['full_name']==snapshot['repository']['full_name']}
    branches=[b for b in snapshot['branches'] if b['name']!=default and b['name'] not in open_heads
              and not b.get('protected') and b['name'].startswith(('feature/','release/','rewrite/'))]
    tags=[t for t in snapshot['tags'] if not stable(t['ref'].removeprefix('refs/tags/'))]
    releases=[r for r in snapshot['releases'] if not stable(r['tag_name'])]
    return branches,tags,releases


def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--repo',required=True,help='OWNER/REPOSITORY on github.com')
    p.add_argument('--keep-release',default='v1.1.2')
    p.add_argument('--backup-dir',type=Path,help='New private directory outside this checkout; required with --apply')
    p.add_argument('--delete-runs',action='store_true',help='Also remove completed runs unrelated to retained stable tags or current main')
    p.add_argument('--apply',action='store_true')
    a=p.parse_args()
    if os.environ.get('GH_HOST','github.com')!='github.com':p.error('Use the github.com GitHub CLI host for this tool')
    if not re.fullmatch(r'[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+',a.repo):p.error('Use OWNER/REPOSITORY')
    if not stable(a.keep_release):p.error('Keep a stable release at version 1.0 or later')
    prefix='repos/'+a.repo
    repository=api(prefix)
    info=api(prefix+'/contents/appinfo/info.xml')
    if ET.fromstring(base64.b64decode(info['content'])).findtext('id')!='deckcalendarbridge':p.error('Not a Deck Calendar Bridge repository')
    release=api(prefix+'/releases/tags/'+a.keep_release)
    if release['draft'] or release['prerelease']:p.error('The retained official release must be published and stable')
    snapshot={'repository':repository,'branches':pages(prefix+'/branches?per_page=100'),
              'tags':api(prefix+'/git/matching-refs/tags/'),
              'releases':pages(prefix+'/releases?per_page=100'),
              'pulls':pages(prefix+'/pulls?state=all&per_page=100')}
    branches,tags,releases=selection(snapshot)
    retained={next(b['commit']['sha'] for b in snapshot['branches'] if b['name']==repository['default_branch'])}
    for tag in snapshot['tags']:
        if stable(tag['ref'].removeprefix('refs/tags/')):
            obj=tag['object']
            while obj['type']=='tag':obj=api(prefix+'/git/tags/'+obj['sha'])['object']
            retained.add(obj['sha'])
    runs=pages(prefix+'/actions/runs?per_page=100','workflow_runs') if a.delete_runs else []
    remove_runs=[r for r in runs if r['status']=='completed' and r['head_sha'] not in retained]
    plan={'repository':a.repo,'apply':a.apply,'retain_stable_releases':'all versions 1.0 and later',
          'delete_branches':[{ 'name':b['name'],'expected_sha':b['commit']['sha']} for b in branches],
          'delete_tags':[t['ref'] for t in tags],'delete_releases':[r['tag_name'] for r in releases],
          'delete_completed_runs':[r['id'] for r in remove_runs]}
    print(json.dumps(plan,indent=2),flush=True)
    if not a.apply:return 0
    if not a.backup_dir:p.error('--apply requires --backup-dir')
    backup=a.backup_dir.resolve(); checkout=Path(__file__).resolve().parents[1]
    if backup==checkout or checkout in backup.parents:p.error('Keep the private backup outside this checkout')
    if backup.exists():p.error('Choose a new backup directory; do not overwrite an earlier backup')
    backup.mkdir(parents=True,mode=0o700); os.chmod(backup,0o700)
    snapshot['plan']=plan; snapshot['runs']=runs
    snapshot['issue_comments']=pages(prefix+'/issues/comments?per_page=100')
    snapshot['review_comments']=pages(prefix+'/pulls/comments?per_page=100')
    (backup/'github-metadata.json').write_text(json.dumps(snapshot,indent=2))
    git('clone','--mirror','https://github.com/'+a.repo+'.git',str(backup/'repository.git'))
    git('-C',str(backup/'repository.git'),'bundle','create',str(backup/'repository.bundle'),'--all')
    for r in releases:
        if r['assets']:
            dest=backup/'release-assets'/str(r['id']); dest.mkdir(parents=True)
            run(['gh','release','download',r['tag_name'],'--repo',a.repo,'--dir',str(dest)])
    # Run logs can contain private data. Store them only in this private backup.
    for r in remove_runs:
        dest=backup/'run-logs'/str(r['id']); dest.parent.mkdir(exist_ok=True)
        result=subprocess.run(['gh','api',prefix+'/actions/runs/'+str(r['id'])+'/logs'],capture_output=True)
        if result.returncode:
            error=result.stderr.decode(errors='replace')
            if not re.search(r'HTTP (404|410)',error):raise RuntimeError('Cannot back up run '+str(r['id'])+': '+error)
            dest.with_suffix('.unavailable.txt').write_text(error)
        else:dest.with_suffix('.zip').write_bytes(result.stdout)
    print('Backup complete: '+str(backup),flush=True)
    for b in branches:
        open_now=pages(prefix+'/pulls?state=open&per_page=100')
        if any(p['head']['ref']==b['name'] for p in open_now):
            print('Keeping newly opened PR branch '+b['name']);continue
        ref='refs/heads/'+b['name']
        git('push','--force-with-lease='+ref+':'+b['commit']['sha'],'https://github.com/'+a.repo+'.git',':'+ref)
    for r in releases:
        current=api(prefix+'/releases/'+str(r['id']))
        if current['updated_at']!=r['updated_at']:raise RuntimeError('Release changed after backup; review '+r['tag_name'])
        run(['gh','release','delete',r['tag_name'],'--repo',a.repo,'--yes'])
    for t in tags:
        git('push','--force-with-lease='+t['ref']+':'+t['object']['sha'],'https://github.com/'+a.repo+'.git',':'+t['ref'])
    for r in remove_runs:
        current=api(prefix+'/actions/runs/'+str(r['id']))
        if current['status']=='completed' and current['head_sha']==r['head_sha']:
            api(prefix+'/actions/runs/'+str(r['id']),'DELETE')
    print('Cleanup finished. Existing commit/PR history remains private; this is not history sanitization.')
    return 0


if __name__=='__main__':raise SystemExit(main())
