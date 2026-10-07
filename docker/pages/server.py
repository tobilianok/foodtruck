"""Foodtruck pages - service interne : pages d'une fiche de recette ou d'un ticket en images, pour le modèle de vision.

    POST /pages  corps = le fichier (PDF ou image), en-têtes Content-Type et X-Dpi (200 par défaut)
                 → {"pages": ["<PNG en base64>", ...]}  4 pages au plus, 2600 pixels au plus de côté, couleur
                 En-tête X-Mode: ticket (v0.19.0) : un ticket long et étroit (Lidl Plus : 1290 × 8648 pixels) est découpé
                 en morceaux successifs lisibles (voir decouper) ; les pages ordinaires (A4 du Leclerc Drive) sont
                 rendues comme d'habitude.
    GET  /sante  → {"ok": true, "poppler": "…"}

Aucune reconnaissance de texte ici : la lecture est faite par le modèle de vision (Ollama sur le PC de Louis). Uniquement
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

# v0.19.0 : découpe des tickets longs (réglages validés par l'essai du 7 octobre 2026 sur les 6 tickets de Louis)
LARGEUR = 1000       # largeur des morceaux (les chiffres font encore ~27 pixels de haut)
HAUTEUR = 1300       # hauteur visée d'un morceau (≈ 1 300 jetons d'image chacun)
LONG = 1.8           # hauteur / largeur au-delà de laquelle une page est un ticket long
ENCRE = 150          # niveau de gris en dessous duquel un pixel est de l'encre
BLANC_MAX = 48       # un blanc vertical plus haut que ça…
BLANC_GARDE = 24     # …est ramené à cette hauteur
MORCEAUX_MAX = 12    # par page
IMAGES_MAX = 16      # par ticket

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


def est_long(image):
    return image.height > LONG * image.width


def _encre(image):
    """Masque de l'encre (255 = encre)."""
    return image.convert('L').point(lambda v: 255 if v < ENCRE else 0)


def _lignes_vides(masque):
    """True pour chaque ligne de pixels sans encre."""
    profil = masque.resize((1, masque.height), Image.BOX)
    return [v == 0 for v in profil.tobytes()]


def decouper(image):
    """Morceaux successifs (RGB) d'un ticket long : marges blanches retirées, largeur ramenée à LARGEUR, grands blancs
    raccourcis, coupes toujours dans un blanc entre deux lignes de texte (aucune ligne coupée ni répétée)."""
    image = image.convert('RGB')
    cadre = _encre(image).getbbox()
    if cadre:
        x1, y1, x2, y2 = cadre
        marge = max(10, image.width // 60)
        image = image.crop((max(0, x1 - marge), max(0, y1 - marge), min(image.width, x2 + marge), min(image.height, y2 + marge)))
    if image.width > LARGEUR:
        image = image.resize((LARGEUR, round(image.height * LARGEUR / image.width)), Image.LANCZOS)

    vides = _lignes_vides(_encre(image))
    garder, y = [], 0
    while y < len(vides):
        fin = y
        while fin < len(vides) and vides[fin] == vides[y]:
            fin += 1
        if vides[y] and fin - y > BLANC_MAX:
            garder.append((y, y + BLANC_GARDE // 2))
            garder.append((fin - BLANC_GARDE // 2, fin))
        else:
            garder.append((y, fin))
        y = fin
    hauteur = sum(b - a for a, b in garder)
    if hauteur < image.height:
        tasse = Image.new('RGB', (image.width, hauteur), 'white')
        y = 0
        for a, b in garder:
            tasse.paste(image.crop((0, a, image.width, b)), (0, y))
            y += b - a
        image = tasse
        vides = _lignes_vides(_encre(image))

    cible = max(HAUTEUR, -(-image.height // MORCEAUX_MAX))
    morceaux, debut = [], 0
    while image.height - debut > cible * 1.15:
        de, a = debut + int(cible * 0.7), min(image.height, debut + cible)
        coupe, meilleur, y = None, 0, de
        while y < a:
            if vides[y]:
                fin = y
                while fin < a and vides[fin]:
                    fin += 1
                if fin - y >= meilleur:
                    meilleur, coupe = fin - y, (y + fin) // 2
                y = fin
            else:
                y += 1
        if coupe is None:
            profil = list(_encre(image.crop((0, de, image.width, a))).resize((1, a - de), Image.BOX).tobytes())
            coupe = de + profil.index(min(profil))
        morceaux.append(image.crop((0, debut, image.width, coupe)))
        debut = coupe
    morceaux.append(image.crop((0, debut, image.width, image.height)))
    return morceaux


def encode(image):
    out = io.BytesIO()
    image.save(out, 'PNG', optimize=True)
    return base64.b64encode(out.getvalue()).decode('ascii')


def tailles_pdf(source):
    """{n° de page: (largeur, hauteur) en points} d'après pdfinfo ; {} si pdfinfo échoue."""
    try:
        sortie = run(['pdfinfo', '-f', '1', '-l', str(MAX_PAGES), source])
    except Exception:
        return {}
    import re
    return {int(m.group(1)): (float(m.group(2)), float(m.group(3)))
            for m in re.finditer(r'^Page\s+(\d+)\s+size:\s+([\d.]+)\s+x\s+([\d.]+)', sortie, re.M)}


def ticket(body, mime, dpi):
    """v0.19.0 : images d'un ticket. Pages longues découpées en morceaux, pages ordinaires rendues à `dpi`."""
    images = []
    if body[:5] == b'%PDF-' or mime == 'application/pdf':
        with tempfile.TemporaryDirectory(prefix='foodtruck-pages-') as workdir:
            source = os.path.join(workdir, 'source.pdf')
            with open(source, 'wb') as f:
                f.write(body)
            tailles = tailles_pdf(source)
            for n in range(1, MAX_PAGES + 1):
                if tailles and n not in tailles:
                    break
                w, h = tailles.get(n, (0, 0))
                # Ticket long : rendu à 1600 pixels de large (assez pour la découpe, sans image géante)
                taille = ['-scale-to-x', '1600', '-scale-to-y', '-1'] if h > LONG * w > 0 else ['-r', str(dpi)]
                prefixe = os.path.join(workdir, 'p%d' % n)
                try:
                    run(['pdftoppm', *taille, '-f', str(n), '-l', str(n), '-png', source, prefixe])
                except PagesError:
                    if n == 1:
                        raise
                    break
                noms = sorted(x for x in os.listdir(workdir) if x.startswith('p%d' % n) and x.endswith('.png'))
                if not noms:
                    break
                with Image.open(os.path.join(workdir, noms[0])) as image:
                    images.append(image.convert('RGB'))
        if not images:
            raise PagesError('PDF sans page lisible.')
    else:
        try:
            image = Image.open(io.BytesIO(body))
            images.append(ImageOps.exif_transpose(image).convert('RGB'))
        except Exception as e:
            raise PagesError(f'Fichier illisible ({mime}) : {e}')

    out = []
    for image in images:
        if est_long(image):
            out.extend(encode(m) for m in decouper(image))
        else:
            out.append(png(image))
    if len(out) > IMAGES_MAX:
        raise PagesError(f'Ticket trop long : {len(out)} morceaux (au plus {IMAGES_MAX}).')
    return out


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
            mode = (self.headers.get('X-Mode') or '').strip().lower()
            with LOCK:
                result = ticket(body, mime, dpi) if mode == 'ticket' else pages(body, mime, dpi)
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
