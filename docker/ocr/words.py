"""Foodtruck OCR - mots collés par la reconnaissance de texte.

Sur les petits caractères serrés, Tesseract oublie parfois l'espace entre deux mots : « thaïléger »,
« surfeumoyenavecunpetitfilet », « entemps », « Ajoutez-yla », « personne2min ». Un mot absent du dictionnaire
français qui se découpe en mots du dictionnaire est recoupé.

Prudence avant tout (un mot rare ne doit pas être cassé) :
- seuls les mots en minuscules sont examinés (noms propres, marques, sigles laissés tels quels) ;
- un mot connu n'est jamais touché ; un mot de moins de 3 lettres non plus ;
- morceaux d'une ou deux lettres : seulement les petits mots de liaison (« à », « du », « et »…) ;
- un mot court (moins de 8 lettres), ou découpé en plus de deux morceaux, n'est recoupé que si un des morceaux est
  un petit mot de liaison, cas de loin le plus fréquent (« carbonara » n'est pas « car bon ara ») ;
- le découpage retenu est celui qui a le moins de morceaux, puis les morceaux les plus longs.

Dictionnaire : liste « an-array-of-french-words » (licence MIT, 336 000 formes), complétée par le vocabulaire de
cuisine qui lui manque (SUPPLEMENT).
"""

import json
import os
import re

DICTIONARY = os.environ.get('OCR_WORDS', os.path.join(os.path.dirname(os.path.abspath(__file__)), 'mots-fr.json'))

SUPPLEMENT = {
    'thaï', 'thaïe', 'thaïs', 'thaïes', 'wok', 'woks', 'orzo', 'risotto', 'pesto', 'tahini', 'tofu', 'tempeh', 'miso',
    'sriracha', 'harissa', 'feta', 'ricotta', 'burrata', 'mascarpone', 'gnocchi', 'gnocchis', 'tagliatelle', 'penne',
    'fusilli', 'linguine', 'udon', 'soba', 'ramen', 'naan', 'wrap', 'wraps', 'tortilla', 'tortillas', 'bulgur', 'boulgour',
    'quinoa', 'panko', 'teriyaki', 'tandoori', 'masala', 'curcuma', 'zaatar', 'dukkah', 'sumac', 'paprika', 'cajun',
    'chorizo', 'pancetta', 'guacamole', 'houmous', 'falafel', 'falafels', 'cc', 'cs', 'min', 'pers',
    # Sans accent (cartes de kits, lectures approximatives) et enseignes : jamais recoupés
    'piece', 'pieces', 'creme', 'cremes', 'cuillere', 'cuilleres', 'leclerc', 'hellofresh', 'carrefour', 'lidl', 'auchan',
    'intermarche', 'monoprix', 'picard', 'marmiton', 'quitoque', 'cuisineaz',
}

# Petits mots de liaison : un mot court collé contient presque toujours l'un d'eux
LINKS = {
    'à', 'a', 'y', 'de', 'du', 'des', 'le', 'la', 'les', 'et', 'en', 'au', 'aux', 'un', 'une', 'ce', 'ces', 'se', 'sa',
    'son', 'ses', 'sur', 'par', 'pour', 'avec', 'dans', 'ou', 'qui', 'que', 'il', 'elle', 'on', 'puis', 'bien', 'plus',
}

_words = None


def words():
    global _words
    if _words is None:
        try:
            with open(DICTIONARY, encoding='utf-8') as f:
                _words = set(json.load(f))
        except OSError:
            _words = set()
        _words |= SUPPLEMENT
    return _words


def known(word):
    return word in words()


def _split(token):
    """Meilleur découpage de token en mots du dictionnaire, ou None."""
    n = len(token)
    best = [None] * (n + 1)
    best[0] = (0, 0, [])  # (nombre de morceaux, -longueur du plus petit morceau, morceaux)
    for end in range(1, n + 1):
        for start in range(max(0, end - 25), end):
            if best[start] is None:
                continue
            piece = token[start:end]
            # Morceaux d'une ou deux lettres : seulement les petits mots de liaison (« à », « du », « et »…)
            if len(piece) <= 2 and piece not in LINKS:
                continue
            if not known(piece):
                continue
            count, neg_min, pieces = best[start]
            candidate = (count + 1, max(neg_min, -len(piece)), pieces + [piece])
            if best[end] is None or candidate[:2] < best[end][:2]:
                best[end] = candidate
    result = best[n]
    if result is None or len(result[2]) < 2 or len(result[2]) > 8:
        return None
    pieces = result[2]
    # Mot court, ou plus de deux morceaux : il faut un petit mot de liaison (« car bon ara » refusé)
    if (n < 8 or len(pieces) > 2) and not any(p in LINKS for p in pieces):
        return None
    return pieces


def fix_token(token):
    """« thaïléger » → « thaï léger » ; « Ajoutez-yla » → « Ajoutez-y la » ; « personne2min » → « personne 2min »."""
    if len(token) < 3 or re.search(r'www|https?:|@|\.[a-z]', token, re.I):
        return token  # adresse, courriel : jamais recoupés

    # Lettres collées à un nombre : « personne2min », « riz12-14 »
    m = re.match(r'^([a-zà-öø-ÿœæ]{3,})(\d.*)$', token)
    if m and known(m.group(1)):
        return m.group(1) + ' ' + m.group(2)

    # Virgule collée entre deux mots : « casserole,ou » (jamais entre deux chiffres : « 1,5 »)
    if re.search(r'[a-zà-öø-ÿœæ],[a-zà-öø-ÿœæ]', token, re.I):
        return ', '.join(fix_token(p) for p in token.split(','))

    # Traits d'union et apostrophes : chaque morceau est examiné (« Ajoutez-yla », « jusqu'àce »)
    parts = re.split(r"([-'’])", token)
    if len(parts) > 1:
        return ''.join(fix_token(p) if p not in "-'’" else p for p in parts)

    # Ponctuation finale gardée à part (« entemps. »)
    m = re.match(r'^(.*?)([.,;:!?)»]*)$', token)
    core, tail = m.group(1), m.group(2)
    if len(core) < 3 or core != core.lower() or not re.fullmatch(r'[a-zà-öø-ÿœæ]+', core) or known(core):
        return token
    pieces = _split(core)
    return (' '.join(pieces) + tail) if pieces else token


def fix_text(text):
    return ' '.join(fix_token(t) for t in text.split(' '))
