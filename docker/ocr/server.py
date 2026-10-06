"""Foodtruck OCR - service interne de lecture des fiches de recettes.

    POST /lire   corps = le fichier (PDF ou image), en-tête Content-Type, en-tête X-Titre facultatif (titre encodé)
                 → {"version": 1, "pages": [{"width", "height", "rotation", "blocks": [...]}], "titre": "…"}
    GET  /sante  → {"ok": true, "tesseract": "5.3.0", "langues": [...]}

Uniquement sur le réseau interne de la stack (aucun port publié). Une lecture à la fois : les demandes suivantes
attendent leur tour ; /sante répond même pendant une lecture. Aucun fichier n'est conservé : tout passe par un dossier temporaire effacé après la lecture.
"""

import json
import os
import re
import subprocess
import sys
import tempfile
import threading
import urllib.parse
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

from PIL import Image, ImageChops, ImageFilter, ImageOps

from layout import add_missed_lines, blocks_from_words, words_from_tsv
from words import fix_text, fix_token, words as dictionary

PORT = int(os.environ.get('OCR_PORT', '8080'))
LANG = os.environ.get('OCR_LANG', 'fra')
DPI = int(os.environ.get('OCR_DPI', '300'))
MAX_BYTES = 40 * 1024 * 1024
MAX_PAGES = 8
TIMEOUT = 180
ENV = dict(os.environ, OMP_THREAD_LIMIT=os.environ.get('OMP_THREAD_LIMIT', '1'))

# Une lecture à la fois (la VM est modeste) ; /sante reste joignable pendant une lecture
READ_LOCK = threading.Lock()


class ReadError(Exception):
    pass


def run(args, timeout=TIMEOUT):
    result = subprocess.run(args, capture_output=True, timeout=timeout, env=ENV)
    if result.returncode != 0:
        raise ReadError(f"{args[0]} : {result.stderr.decode('utf-8', 'replace').strip()[:300]}")
    return result.stdout.decode('utf-8', 'replace')


def pages_from_file(path, mime, workdir):
    """Images PNG, une par page, à la bonne résolution."""
    with open(path, 'rb') as f:
        head = f.read(5)
    if head == b'%PDF-' or mime == 'application/pdf':
        run(['pdftoppm', '-r', str(DPI), '-l', str(MAX_PAGES), '-png', path, os.path.join(workdir, 'page')])
        pages = sorted(p for p in os.listdir(workdir) if p.startswith('page') and p.endswith('.png'))
        if not pages:
            raise ReadError('PDF sans page lisible.')
        return [os.path.join(workdir, p) for p in pages]

    try:
        image = Image.open(path)
        frames = []
        for i in range(min(getattr(image, 'n_frames', 1), MAX_PAGES)):
            image.seek(i)
            frame = ImageOps.exif_transpose(image.copy()).convert('RGB')
            # Petite photo : agrandie pour que Tesseract lise les petits caractères
            if max(frame.size) < 2000:
                factor = 2000 / max(frame.size)
                frame = frame.resize((int(frame.width * factor), int(frame.height * factor)), Image.LANCZOS)
            out = os.path.join(workdir, f'page-{i + 1:02d}.png')
            frame.save(out)
            frames.append(out)
        return frames
    except ReadError:
        raise
    except Exception as e:  # format non reconnu
        raise ReadError(f'Fichier illisible ({mime}) : {e}')


def orient(page):
    """Page posée de travers (photo, scan tourné) : redressée d'après la détection d'orientation de Tesseract."""
    try:
        osd = run(['tesseract', page, 'stdout', '--psm', '0', '-l', 'osd'], timeout=60)
    except (ReadError, subprocess.TimeoutExpired):
        return 0  # trop peu de texte pour décider : page laissée telle quelle
    rotate = re.search(r'Rotate:\s*(\d+)', osd)
    conf = re.search(r'Orientation confidence:\s*([\d.]+)', osd)
    angle = int(rotate.group(1)) if rotate else 0
    if angle and conf and float(conf.group(1)) >= 2:
        with Image.open(page) as image:
            image.rotate(-angle, expand=True).save(page)
        return angle
    return 0


def enhance(page):
    """Gris par le canal le plus sombre (un titre vert ou orange devient noir, le fond blanc reste blanc), contraste
    étiré et netteté renforcée : bien meilleure lecture des petits caractères, des quantités et des titres en couleur
    sur les scans de photocopieuse (mesuré sur les fiches Leclerc et HelloFresh : 33 repères sur 34 contre 31)."""
    with Image.open(page) as image:
        r, g, b = image.convert('RGB').split()
        gray = ImageOps.autocontrast(ImageChops.darker(ImageChops.darker(r, g), b), cutoff=1)
        gray.filter(ImageFilter.UnsharpMask(radius=2, percent=120, threshold=3)).save(page)


def read_page(page):
    enhance(page)
    rotation = orient(page)
    with Image.open(page) as image:
        width, height = image.size
    words = words_from_tsv(run(['tesseract', page, 'stdout', '-l', LANG, '--psm', '3', 'tsv']))
    # Seconde lecture « texte épars » : zones prises pour des images par la première (voir add_missed_lines)
    words = add_missed_lines(words, words_from_tsv(run(['tesseract', page, 'stdout', '-l', LANG, '--psm', '11', 'tsv'])))
    # Mots collés par la lecture (« thaïléger », « surfeumoyenavecunpetitfilet ») recoupés d'après le dictionnaire
    for w in words:
        w['t'] = fix_token(w['t'])
    return {'width': width, 'height': height, 'rotation': rotation, 'blocks': blocks_from_words(words, width)}


def read(body, mime, title=None):
    with tempfile.TemporaryDirectory(prefix='foodtruck-ocr-') as workdir:
        source = os.path.join(workdir, 'source')
        with open(source, 'wb') as f:
            f.write(body)
        result = {'version': 1, 'pages': [read_page(p) for p in pages_from_file(source, mime, workdir)]}
        if title:
            # Titre du document Paperless, lu par Paperless : mêmes mots collés possibles (« Curry thaïléger »)
            result['titre'] = fix_text(title)
        return result


def health():
    version = run(['tesseract', '--version'], timeout=20).splitlines()[0].replace('tesseract', '').strip()
    langs = [l.strip() for l in run(['tesseract', '--list-langs'], timeout=20).splitlines()[1:] if l.strip()]
    return {'ok': LANG in langs, 'tesseract': version, 'langues': langs, 'mots': len(dictionary())}


class Handler(BaseHTTPRequestHandler):
    server_version = 'foodtruck-ocr/1'

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
        if self.path.rstrip('/') != '/lire':
            self.reply(404, {'erreur': 'Adresse inconnue.'})
            return
        length = int(self.headers.get('Content-Length') or 0)
        if length <= 0 or length > MAX_BYTES:
            self.reply(413, {'erreur': 'Fichier vide ou trop gros (40 Mo au plus).'})
            return
        body = self.rfile.read(length)
        mime = (self.headers.get('Content-Type') or '').split(';')[0].strip().lower()
        title = urllib.parse.unquote(self.headers.get('X-Titre') or '')[:300] or None
        try:
            with READ_LOCK:
                result = read(body, mime, title)
            self.reply(200, result)
        except ReadError as e:
            self.reply(422, {'erreur': str(e)})
        except subprocess.TimeoutExpired:
            self.reply(504, {'erreur': 'Lecture trop longue : page abandonnée.'})
        except Exception as e:
            self.reply(500, {'erreur': f'Erreur interne : {e}'})

    def log_message(self, fmt, *args):
        sys.stderr.write('%s - %s\n' % (self.address_string(), fmt % args))


if __name__ == '__main__':
    print(f'foodtruck-ocr à l\'écoute sur le port {PORT} (langue {LANG}, {DPI} dpi)', flush=True)
    server = ThreadingHTTPServer(('0.0.0.0', PORT), Handler)
    server.daemon_threads = True
    server.serve_forever()
