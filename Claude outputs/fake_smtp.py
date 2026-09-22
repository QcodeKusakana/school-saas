#!/usr/bin/env python3
"""
Serveur SMTP de test — pour ÉPROUVER le client, pas pour le croire.

Parle assez de protocole pour que `app/core/smtp.php` soit exercé sur
un vrai dialogue : EHLO, AUTH LOGIN/PLAIN, MAIL FROM, RCPT TO, DATA.

Écrit chaque message reçu dans un fichier .eml, ce qui permet de
vérifier les EN-TÊTES réellement émis — encodage des accents, absence
d'injection, structure multipart.

Usage :
    python3 tests/fake_smtp.py <port> <dossier_sortie> [--auth user:pass]
                               [--fail-count N]

--fail-count N : rejette les N premières transactions avec un 451.
                 Sert à prouver le rejeu de la file.
"""
import base64
import os
import socket
import socketserver
import sys
import threading
import time

PORT = int(sys.argv[1])
OUTDIR = sys.argv[2]
AUTH = None
FAIL_COUNT = 0

for i, a in enumerate(sys.argv):
    if a == '--auth':
        AUTH = sys.argv[i + 1]
    if a == '--fail-count':
        FAIL_COUNT = int(sys.argv[i + 1])

os.makedirs(OUTDIR, exist_ok=True)

_lock = threading.Lock()
_state = {'failed': 0, 'received': 0}


class Handler(socketserver.StreamRequestHandler):
    timeout = 20

    def send(self, line):
        self.wfile.write((line + "\r\n").encode())
        self.wfile.flush()

    def handle(self):
        self.send('220 fake.local ESMTP prêt')
        authenticated = AUTH is None
        mail_from = None
        rcpt = []

        while True:
            try:
                raw = self.rfile.readline()
            except Exception:
                return

            if not raw:
                return

            line = raw.decode('utf-8', 'replace').rstrip("\r\n")
            upper = line.upper()

            if upper.startswith('EHLO'):
                self.send('250-fake.local')
                self.send('250-SIZE 10485760')
                if AUTH:
                    self.send('250-AUTH LOGIN PLAIN')
                self.send('250 HELP')

            elif upper.startswith('HELO'):
                self.send('250 fake.local')

            elif upper.startswith('AUTH LOGIN'):
                self.send('334 VXNlcm5hbWU6')
                user = base64.b64decode(self.rfile.readline().strip()).decode()
                self.send('334 UGFzc3dvcmQ6')
                pwd = base64.b64decode(self.rfile.readline().strip()).decode()
                if AUTH and f'{user}:{pwd}' == AUTH:
                    authenticated = True
                    self.send('235 Authentification acceptée')
                else:
                    self.send('535 Identifiants refusés')

            elif upper.startswith('AUTH PLAIN'):
                payload = line.split(' ', 2)[2] if len(line.split(' ')) > 2 else ''
                decoded = base64.b64decode(payload).decode().split('\0')
                if AUTH and f'{decoded[1]}:{decoded[2]}' == AUTH:
                    authenticated = True
                    self.send('235 Authentification acceptée')
                else:
                    self.send('535 Identifiants refusés')

            elif upper.startswith('MAIL FROM'):
                if not authenticated:
                    self.send('530 Authentification requise')
                    continue
                mail_from = line
                self.send('250 OK')

            elif upper.startswith('RCPT TO'):
                rcpt.append(line)
                self.send('250 OK')

            elif upper == 'DATA':
                with _lock:
                    if _state['failed'] < FAIL_COUNT:
                        _state['failed'] += 1
                        self.send('451 Indisponible, réessayez plus tard')
                        continue

                self.send('354 Envoyez le message, terminez par un point seul')
                chunks = []
                while True:
                    l = self.rfile.readline()
                    if not l or l in (b".\r\n", b".\n"):
                        break
                    chunks.append(l)

                body = b''.join(chunks)

                with _lock:
                    _state['received'] += 1
                    n = _state['received']

                path = os.path.join(OUTDIR, f'msg{n:03d}.eml')
                with open(path, 'wb') as fh:
                    fh.write(f'X-Test-MailFrom: {mail_from}\r\n'.encode())
                    for r in rcpt:
                        fh.write(f'X-Test-Rcpt: {r}\r\n'.encode())
                    fh.write(body)

                self.send('250 Message accepté')
                mail_from, rcpt = None, []

            elif upper == 'QUIT':
                self.send('221 Au revoir')
                return

            elif upper == 'RSET':
                mail_from, rcpt = None, []
                self.send('250 OK')

            elif upper == 'NOOP':
                self.send('250 OK')

            else:
                self.send('502 Commande inconnue')


class Server(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True


if __name__ == '__main__':
    with Server(('127.0.0.1', PORT), Handler) as srv:
        print(f'fake-smtp écoute sur 127.0.0.1:{PORT}', flush=True)
        srv.serve_forever()
