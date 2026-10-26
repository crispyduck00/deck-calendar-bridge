#!/usr/bin/env python3
"""Run native NC35 tests with a loopback SMTP sink; never forward any mail."""
import email
import email.policy
import json
import os
from pathlib import Path
import re
import socketserver
import subprocess
import sys
import tempfile
import threading

root = Path(sys.argv[1]).resolve()
if not (root.parent / '.dcb-disposable').is_file():
    raise SystemExit('Disposable test marker required')
php = os.environ.get('DCB_PHP', 'php')
with tempfile.TemporaryDirectory(prefix='dcb-smtp-') as directory:
    spool = Path(directory)
    class SMTP(socketserver.StreamRequestHandler):
        def send(self, text):
            self.wfile.write((text + '\r\n').encode())
            self.wfile.flush()
        def handle(self):
            recipients = []
            self.send('220 localhost bridge-test')
            while True:
                raw = self.rfile.readline(1048576)
                if not raw:
                    return
                command = raw.decode('utf-8', 'replace').strip()
                verb = command.split(' ', 1)[0].upper()
                if verb in ('EHLO', 'HELO'):
                    self.send('250-localhost')
                    self.send('250 8BITMIME')
                elif verb == 'MAIL':
                    recipients = []
                    self.send('250 OK')
                elif verb == 'RCPT':
                    recipients.append(command.split(':', 1)[1].strip().strip('<>'))
                    self.send('250 OK')
                elif verb == 'DATA':
                    self.send('354 End with a dot')
                    lines = []
                    while True:
                        line = self.rfile.readline(1048576)
                        if line in (b'.\r\n', b'.\n', b''):
                            break
                        lines.append(line[1:] if line.startswith(b'..') else line)
                    message = b''.join(lines)
                    parsed = email.message_from_bytes(message, policy=email.policy.default)
                    calendars = []
                    for part in parsed.walk():
                        if part.get_content_type() != 'text/calendar':
                            continue
                        ics = (part.get_payload(decode=True) or b'').decode('utf-8', 'replace')
                        ics = re.sub(r'\r?\n[ \t]', '', ics)
                        uid = re.search(r'^UID:(.+)$', ics, re.M)
                        method = re.search(r'^METHOD:(.+)$', ics, re.M)
                        calendars.append({'uid': uid.group(1).strip() if uid else '', 'method': method.group(1).strip() if method else '', 'ics': ics})
                    name = str(len(list(spool.glob('*.json')))).zfill(5)
                    (spool / (name + '.eml')).write_bytes(message)
                    (spool / (name + '.json')).write_text(json.dumps({'recipients': recipients, 'calendars': calendars}))
                    self.send('250 Queued')
                elif verb == 'QUIT':
                    self.send('221 Bye')
                    return
                elif verb in ('RSET', 'NOOP'):
                    self.send('250 OK')
                else:
                    self.send('502 Unsupported')
    class Server(socketserver.ThreadingTCPServer):
        allow_reuse_address = True
        daemon_threads = True
    with Server(('127.0.0.1', 0), SMTP) as server:
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        env = dict(os.environ, DCB_SMTP_PORT=str(server.server_address[1]), DCB_MAIL_SPOOL=str(spool))
        result = subprocess.run([php, str(Path(__file__).with_name('nc35.php')), str(root)], env=env)
        server.shutdown()
        messages = list(spool.glob('*.json'))
        print('SMTP sink captured', len(messages), 'messages; no mail was forwarded.', flush=True)
        if result.returncode:
            for p in messages:
                print(p.read_text(), flush=True)
        raise SystemExit(result.returncode)
