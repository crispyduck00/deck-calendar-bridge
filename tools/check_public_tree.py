#!/usr/bin/env python3
"""Check a release tree for credentials and optional operator-supplied private terms."""
import argparse
from pathlib import Path
import re
import subprocess


def inspect(root, paths, denied=()):
    findings=[]
    patterns=[
        ('private-key',re.compile(r'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----')),
        ('access-token',re.compile(r'\bgh[pousr]_[A-Za-z0-9]{30,}\b|\bgithub_pat_[A-Za-z0-9_]{30,}\b')),
        ('cloud-key',re.compile(r'\bAKIA[A-Z0-9]{16}\b')),
    ]
    deny=[re.compile(re.escape(term),re.I) for term in denied if term.strip()]
    for name in paths:
        path=root/name
        if path.is_symlink():
            findings.append((name,0,'symbolic-link')); continue
        try: content=path.read_text(encoding='utf-8')
        except UnicodeDecodeError:
            findings.append((name,0,'non-text-file-needs-review')); continue
        for number,line in enumerate(content.splitlines(),1):
            if any(pattern.search(line) for _,pattern in patterns):
                findings.append((name,number,'credential-pattern'))
            if any(pattern.search(line) for pattern in deny):
                # Never echo a potentially private value into CI output.
                findings.append((name,number,'operator-denied-term'))
    return findings


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--tree',type=Path,default=Path('.'))
    parser.add_argument('--deny-file',type=Path,help='Private UTF-8 terms, one per line; keep this file outside the repository')
    parser.add_argument('--exported',action='store_true',help='Check every file in a history-free export instead of git ls-files')
    args=parser.parse_args(); root=args.tree.resolve()
    denied=args.deny_file.read_text().splitlines() if args.deny_file else []
    if args.exported:
        paths=[str(p.relative_to(root)) for p in root.rglob('*') if (p.is_file() or p.is_symlink()) and '.git' not in p.relative_to(root).parts]
    else:
        paths=subprocess.check_output(['git','-C',str(root),'ls-files','-z']).decode().rstrip('\0').split('\0')
        paths=[p for p in paths if p]
    findings=inspect(root,paths,denied)
    for path,line,kind in findings: print(f'{path}:{line}: {kind}')
    print(f'Checked {len(paths)} files; {len(findings)} finding(s). This pattern check complements manual privacy review.')
    return 1 if findings else 0


if __name__=='__main__': raise SystemExit(main())
