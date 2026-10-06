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
import re
import statistics

MAX_DEPTH = 12

# Unités d'une colonne de quantités dont le chiffre a pu être perdu (« sachet(s) », « pièce(s) »)
UNIT = re.compile(r'^(?:\d*(?:g|kg|ml|cl|l|cs|cc|cm)|pi[eè]ces?(?:\(s\))?|sachets?(?:\(s\))?|paquets?(?:\(s\))?|pots?(?:\(s\))?|bo[iî]tes?(?:\(s\))?|gousses?(?:\(s\))?)\.?$')


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


def _overlaps(a, b):
    """Les deux boîtes se recouvrent sur plus d'un tiers de la plus petite."""
    dx = min(a['x'] + a['w'], b['x'] + b['w']) - max(a['x'], b['x'])
    dy = min(a['y'] + a['h'], b['y'] + b['h']) - max(a['y'], b['y'])
    return dx > 0 and dy > 0 and dx * dy > min(a['w'] * a['h'], b['w'] * b['h']) / 3


def add_missed_lines(words, sparse):
    """Lignes que la lecture normale a sautées, reprises de la lecture « texte épars » (--psm 11).

    La lecture normale de Tesseract range parfois une zone en « image » et n'y lit rien : bandeau « À table dans :
    35 - 45 Min » à côté d'une pastille de couleur, légende sur une photo. La lecture « texte épars » lit tout, mais
    découpe les tableaux en morceaux : on garde donc la lecture normale et on n'y ajoute que les lignes éparses dont
    aucun mot ne recouvre un mot déjà lu, nettes (confiance moyenne d'au moins 70) et faites de vrais mots.
    """
    by_line = {}
    for w in sparse:
        by_line.setdefault(w['line'], []).append(w)
    added = []
    for key, line in by_line.items():
        text = ''.join(w['t'] for w in line)
        if sum(1 for c in text if c.isalnum()) < 3 or statistics.mean(w['conf'] for w in line) < 70:
            continue
        if any(_overlaps(w, o) for w in line for o in words):
            continue
        added.extend(dict(w, line=('epars',) + key) for w in line)
    return words + added


def _gaps(intervals, minimum):
    out, end = [], None
    for a, b in sorted(intervals):
        if end is not None and a - end > minimum:
            out.append((end, a))
        end = b if end is None else max(end, b)
    return out


def _gutters(words, width, line_height):
    """Abscisses des gouttières entre colonnes.

    Pour chaque position horizontale, on compte les lignes de texte qui la traversent. Une gouttière est une bande
    verticale traversée par (presque) aucune ligne, plus large qu'un espace entre deux mots : quelques mots qui
    débordent (titre, paragraphe d'allergènes) sont tolérés. Un tableau « nom … quantité » n'est pas coupé.
    """
    by_line = {}
    for w in words:
        by_line.setdefault(w['line'], []).append(w)
    lines = list(by_line.values())
    if len(lines) < 2:
        # Une seule ligne (rangée de titres « Les ingrédients   La recette ») : un vrai blanc, bien plus large qu'un espace
        gaps = _gaps([(w['x'], w['x'] + w['w']) for w in words], max(width * 0.012, line_height * 1.2))
        return [(a + b) / 2 for a, b in gaps]

    x0 = min(w['x'] for w in words)
    x1 = max(w['x'] + w['w'] for w in words)
    step = 2
    size = (x1 - x0) // step + 1
    cover = [0] * size
    for line in lines:
        seen = bytearray(size)
        for w in line:
            for i in range((w['x'] - x0) // step, min((w['x'] + w['w'] - x0) // step + 1, size)):
                seen[i] = 1
        for i in range(size):
            cover[i] += seen[i]

    # Beaucoup de lignes : une gouttière étroite (30 px à 300 dpi) traversée par quelques mots suffit. Peu de lignes
    # (paragraphe justifié, bout de colonne) : il faut un vrai blanc, sinon les espaces alignés par hasard couperaient le texte.
    if len(lines) >= 8:
        tolerance, minimum = max(1, len(lines) // 12), max(line_height * 0.9, width * 0.007)
    else:
        tolerance, minimum = 0, max(line_height * 1.5, width * 0.012)

    gutters, start = [], None
    for i in range(size + 1):
        empty = i < size and cover[i] <= tolerance
        if empty and start is None:
            start = i
        elif not empty and start is not None:
            a, b = x0 + start * step, x0 + i * step
            if start > 0 and i < size and b - a >= minimum:
                gutters.append((a, b))
            start = None

    # On retire une à une les fausses gouttières (tableau, bout de ligne), puis on réévalue avec les voisines restantes
    middles = [(a + b) / 2 for a, b in gutters]
    changed = True
    while changed and middles:
        changed = False
        bounds = [float('-inf')] + middles + [float('inf')]
        for k, middle in enumerate(middles):
            left = [w for w in words if bounds[k] < w['x'] + w['w'] / 2 <= middle]
            right = [w for w in words if middle < w['x'] + w['w'] / 2 <= bounds[k + 2]]
            # Un bout de ligne prolonge toujours une ligne commencée à sa gauche
            if _is_table_gap(left, right, line_height) or _is_fragment(right, left, line_height):
                del middles[k]
                changed = True
                break
    return middles


def _rows(words):
    """Lignes d'un côté de la gouttière, sans les lettres isolées (puces mal lues)."""
    rows = {}
    for w in words:
        if len(w['t']) >= 2:
            rows.setdefault(w['line'], []).append(w)
    return list(rows.values())


def _is_fragment(side, other, line_height):
    """Quelques bouts de lignes (« péremption », « casserole, ou ») en face de lignes de l'autre côté : c'est la fin
    de ces lignes, pas une colonne."""
    rows, others = _rows(side), _rows(other)
    if not rows or not others or len(rows) > 0.5 * len(others):
        return False
    if len(rows) > 3:
        # Plus de 3 bouts : seulement des bouts de 1 à 3 mots, sur une bande bien plus étroite que les lignes d'en face
        # (deux rangées d'étapes dont les fins de lignes débordent dans la même gouttière)
        span = lambda ws: max(w['x'] + w['w'] for w in ws) - min(w['x'] for w in ws)
        if len(rows) > 8 or any(len(r) > 3 for r in rows) or span([w for r in rows for w in r]) > 0.45 * span([w for r in others for w in r]):
            return False
    ys = [min(w['y'] for w in r) for r in others]
    aligned = sum(1 for r in rows if any(abs(min(w['y'] for w in r) - y) < line_height * 0.7 for y in ys))
    return aligned >= 0.8 * len(rows)


def _is_table_gap(left, right, line_height):
    """Écart entre les noms et les quantités d'un tableau (« Riz … 150g », valeurs nutritionnelles) : à ne pas couper.
    La colonne de droite est faite de lignes courtes avec des chiffres, chacune en face d'une ligne de gauche."""
    by_line = {}
    for w in right:
        by_line.setdefault(w['line'], []).append(w)
    rows = list(by_line.values())
    if not rows or not left:
        return False
    short = [r for r in rows if len(r) <= 3 and (any(c.isdigit() for w in r for c in w['t'])
                                                 or any(UNIT.match(w['t'].lower()) for w in r))]
    if len(short) < 0.6 * len(rows):
        return False
    left_ys = [w['y'] for w in left]
    paired = sum(1 for r in short if any(abs(min(w['y'] for w in r) - y) < line_height * 0.7 for y in left_ys))
    return paired >= 0.7 * len(short)


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

    # Gouttières verticales : colonnes (voir _gutters)
    gutters = _gutters(words, width, line_height)
    if gutters:
        bounds = [float('-inf')] + gutters + [float('inf')]
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
