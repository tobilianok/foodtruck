"""Foodtruck OCR - mise en page d'une page lue par Tesseract.

Entrée : le TSV de Tesseract (un mot par ligne, avec sa boîte). Sortie : des blocs de texte dans l'ordre de lecture.

Méthode « XY-cut » récursive : la page est coupée en bandes horizontales là où il y a un blanc sur toute la
largeur, chaque bande en colonnes là où il y a un blanc sur toute la hauteur, et ainsi de suite. Les colonnes ne
sont donc jamais mélangées, même si Tesseract a lu une ligne d'un bord à l'autre de la page.

Ordre de lecture : bande par bande, de haut en bas ; dans une bande, colonne par colonne, de gauche à droite.
Exception : une bande qui ne contient que des titres d'une ligne (« Les ingrédients | La recette ») au-dessus
d'une bande qui a les mêmes colonnes est rattachée à ces colonnes (chaque titre reste au-dessus de sa colonne).
"""

import csv
import io
import statistics

MAX_DEPTH = 12


def words_from_tsv(tsv: str) -> list:
    words = []
    reader = csv.DictReader(io.StringIO(tsv), delimiter='\t', quoting=csv.QUOTE_NONE)
    for row in reader:
        if row.get('level') != '5':
            continue
        text = (row.get('text') or '').strip()
        try:
            conf = float(row.get('conf') or -1)
        except ValueError:
            conf = -1
        if not text or conf < 0:
            continue
        # Puces, traits et taches isolés mal lus : bruit
        if len(text) == 1 and not text.isalnum() and conf < 60:
            continue
        x, y, w, h = (int(row[k]) for k in ('left', 'top', 'width', 'height'))
        words.append({
            't': text, 'x': x, 'y': y, 'w': w, 'h': h, 'conf': conf,
            'line': (int(row['block_num']), int(row['par_num']), int(row['line_num'])),
        })
    return words


def _gaps(intervals, minimum):
    out, end = [], None
    for a, b in sorted(intervals):
        if end is not None and a - end > minimum:
            out.append((end, a))
        end = b if end is None else max(end, b)
    return out


def _cut(words, width, depth=0):
    """Arbre de découpe : ('leaf', mots) | ('H', enfants de haut en bas) | ('V', enfants de gauche à droite)."""
    if len(words) < 2 or depth > MAX_DEPTH:
        return ('leaf', words)
    line_height = statistics.median(w['h'] for w in words)

    # Blancs horizontaux : bandes
    hgaps = _gaps([(w['y'], w['y'] + w['h']) for w in words], line_height * 1.6)
    if hgaps:
        bounds = [float('-inf')] + [(a + b) / 2 for a, b in hgaps] + [float('inf')]
        parts = [[w for w in words if bounds[i] < w['y'] + w['h'] / 2 <= bounds[i + 1]] for i in range(len(bounds) - 1)]
        parts = [p for p in parts if p]
        if len(parts) > 1:
            return ('H', [_cut(p, width, depth + 1) for p in parts])

    # Blancs verticaux : colonnes (plus large qu'un espace entre deux mots)
    vgaps = _gaps([(w['x'], w['x'] + w['w']) for w in words], max(width * 0.012, line_height * 1.2))
    if vgaps:
        bounds = [float('-inf')] + [(a + b) / 2 for a, b in vgaps] + [float('inf')]
        parts = [[w for w in words if bounds[i] < w['x'] + w['w'] / 2 <= bounds[i + 1]] for i in range(len(bounds) - 1)]
        parts = [p for p in parts if p]
        if len(parts) > 1:
            return ('V', [_cut(p, width, depth + 1) for p in parts])

    return ('leaf', words)


def _all_words(node):
    kind, content = node
    if kind == 'leaf':
        return content
    return [w for child in content for w in _all_words(child)]


def _extent(node):
    ws = _all_words(node)
    return min(w['x'] for w in ws), max(w['x'] + w['w'] for w in ws)


def _lines(words):
    by = {}
    for w in words:
        by.setdefault(w['line'], []).append(w)
    rows = sorted(by.values(), key=lambda ws: (min(w['y'] for w in ws), min(w['x'] for w in ws)))
    return [' '.join(w['t'] for w in sorted(ws, key=lambda w: w['x'])) for ws in rows]


def _is_heading_band(node):
    """Bande de titres : chaque colonne tient sur une ligne courte."""
    if node[0] != 'V':
        return False
    for child in node[1]:
        lines = _lines(_all_words(child))
        if len(lines) != 1 or len(lines[0].split()) > 5:
            return False
    return True


def _aligned(head, body):
    """Chaque titre est au-dessus d'une seule colonne du corps, et chaque colonne a son titre."""
    if body[0] != 'V' or len(head[1]) != len(body[1]):
        return False
    heads = [_extent(c) for c in head[1]]
    cols = [_extent(c) for c in body[1]]
    for i, (ha, hb) in enumerate(heads):
        overlaps = [j for j, (ca, cb) in enumerate(cols) if ha < cb and ca < hb]
        if overlaps != [i]:
            return False
    return True


def _merge_headings(node):
    kind, content = node
    if kind == 'leaf':
        return node
    children = [_merge_headings(c) for c in content]
    if kind == 'H':
        merged, i = [], 0
        while i < len(children):
            if i + 1 < len(children) and _is_heading_band(children[i]) and _aligned(children[i], children[i + 1]):
                head, body = children[i], children[i + 1]
                merged.append(('V', [('H', [h, b]) for h, b in zip(head[1], body[1])]))
                i += 2
                continue
            merged.append(children[i])
            i += 1
        children = merged
    return (kind, children)


def _flatten(node, out):
    kind, content = node
    if kind == 'leaf':
        out.append(content)
        return
    for child in content:
        _flatten(child, out)


def blocks_from_words(words, width):
    if not words:
        return []
    tree = _merge_headings(_cut(words, width))
    leaves = []
    _flatten(tree, leaves)
    blocks = []
    for ws in leaves:
        lines = [l for l in _lines(ws) if l.strip()]
        text = ''.join(lines)
        if sum(1 for c in text if c.isalnum()) < 2:
            continue
        x, y = min(w['x'] for w in ws), min(w['y'] for w in ws)
        blocks.append({
            'x': x, 'y': y,
            'w': max(w['x'] + w['w'] for w in ws) - x,
            'h': max(w['y'] + w['h'] for w in ws) - y,
            'size': int(statistics.median(w['h'] for w in ws)),
            'conf': round(statistics.mean(w['conf'] for w in ws)),
            'lines': lines,
        })
    return blocks
