"""Foodtruck pages - service interne : pages d'une fiche de recette en images, pour le modèle de vision (v0.18.0).

    POST /pages  corps = le fichier (PDF ou image), en-têtes Content-Type et X-Dpi (200 par défaut)
                 → {"pages": ["<PNG en base64>", ...]}  4 pages au plus, 2600 pixels au plus de côté, couleur
    GET  /sante  → {"ok": true, "poppler": "…"}

Aucune reconnaissance de texte ici : la lecture est faite par le modèle de vision (Ollama sur srv-nas). Uniquement
sur le réseau interne de la stack (aucun port publié). Aucun fichier conservé (dossier temporaire effacé).
"""

import base64
import io
import json
import os
import subprocess
import sys
import tempfile
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

from PIL import Image, ImageOps

PORT = int(os.environ.get('PAGES_PORT', '8080'))
MAX_BYTES = 40 * 1024 * 1024
MAX_PAGES = 4
MAX_SIDE = 2600
TIMEOUT = 120

# Une conversion à la fois (mémoire du conteneur limitée)
LOCK = threading.Lock()


class PagesError(Exception):
    pass


def run(args):
    result = subprocess.run(args, capture_output=True, timeout=TIMEOUT)
    if result.returncode != 0:
        raise PagesError(f"{args[0]} : {result.stderr.decode('utf-8', 'replace').strip()[:300]}")
    return result.stdout.decode('utf-8', 'replace')


def png(image):
    """Image en couleur, redressée d'après ses données EXIF (photo de téléphone), réduite si besoin, en PNG base64."""
    image = ImageOps.exif_transpose(image).convert('RGB')
    if max(image.size) > MAX_SIDE:
        factor = MAX_SIDE / max(image.size)
        image = image.resize((int(image.width * factor), int(image.height * factor)), Image.LANCZOS)
    out = io.BytesIO()
    image.save(out, 'PNG', optimize=True)
    return base64.b64encode(out.getvalue()).decode('ascii')


def pages(body, mime, dpi):
    if body[:5] == b'%PDF-' or mime == 'application/pdf':
        with tempfile.TemporaryDirectory(prefix='foodtruck-pages-') as workdir:
            source = os.path.join(workdir, 'source.pdf')
            with open(source, 'wb') as f:
                f.write(body)
            run(['pdftoppm', '-r', str(dpi), '-l', str(MAX_PAGES), '-png', source, os.path.join(workdir, 'page')])
            names = sorted(n for n in os.listdir(workdir) if n.startswith('page') and n.endswith('.png'))
            if not names:
                raise PagesError('PDF sans page lisible.')
            out = []
            for name in names:
                with Image.open(os.path.join(workdir, name)) as image:
                    out.append(png(image))
            return out
    try:
        image = Image.open(io.BytesIO(body))
        out = []
        for i in range(min(getattr(image, 'n_frames', 1), MAX_PAGES)):
            image.seek(i)
            out.append(png(image.copy()))
        return out
    except Exception as e:  # format non reconnu
        raise PagesError(f'Fichier illisible ({mime}) : {e}')


def health():
    # pdftoppm -v écrit sa version sur la sortie d'erreur
    version = subprocess.run(['pdftoppm', '-v'], capture_output=True, timeout=20).stderr.decode('utf-8', 'replace').splitlines()[0]
    return {'ok': True, 'poppler': version.replace('pdftoppm version', '').strip()}


class Handler(BaseHTTPRequestHandler):
    server_version = 'foodtruck-pages/1'

    def reply(self, status, payload):
        data = json.dumps(payload, ensure_ascii=False).encode('utf-8')
        self.send_response(status)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.send_header('Content-Length', str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_GET(self):
        if self.path.rstrip('/') == '/sante':
            try:
                self.reply(200, health())
            except Exception as e:
                self.reply(500, {'ok': False, 'erreur': str(e)})
            return
        self.reply(404, {'erreur': 'Adresse inconnue.'})

    def do_POST(self):
        if self.path.rstrip('/') != '/pages':
            self.reply(404, {'erreur': 'Adresse inconnue.'})
            return
        length = int(self.headers.get('Content-Length') or 0)
        if length <= 0 or length > MAX_BYTES:
            self.reply(413, {'erreur': 'Fichier vide ou trop gros (40 Mo au plus).'})
            return
        body = self.rfile.read(length)
        mime = (self.headers.get('Content-Type') or '').split(';')[0].strip().lower()
        try:
            dpi = max(100, min(int(self.headers.get('X-Dpi') or 200), 300))
        except ValueError:
            dpi = 200
        try:
            with LOCK:
                result = pages(body, mime, dpi)
            self.reply(200, {'pages': result})
        except PagesError as e:
            self.reply(422, {'erreur': str(e)})
        except subprocess.TimeoutExpired:
            self.reply(504, {'erreur': 'Conversion trop longue : fichier abandonné.'})
        except Exception as e:
            self.reply(500, {'erreur': f'Erreur interne : {e}'})

    def log_message(self, fmt, *args):
        sys.stderr.write('%s - %s\n' % (self.address_string(), fmt % args))


if __name__ == '__main__':
    print(f'foodtruck-pages à l\'écoute sur le port {PORT}', flush=True)
    server = ThreadingHTTPServer(('0.0.0.0', PORT), Handler)
    server.daemon_threads = True
    server.serve_forever()
