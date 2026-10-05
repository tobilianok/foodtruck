<?php

namespace App\Support\RecipeScan;

/**
 * Nettoie le texte d'une fiche scannée ou imprimée en PDF : pieds de page, adresses web,
 * en-têtes répétés, ligatures perdues (« �nement » → « finement »), apostrophes typographiques.
 */
class TextCleaner
{
    /** Mots où la ligature perdue est « ffi » et non « fi ». */
    private const FFI_WORDS = ['difficile', 'difficiles', 'difficulté', 'efficace', 'efficaces', 'officiel', 'officielle', 'afficher', 'suffisant', 'suffisante', 'suffisamment', 'office', 'coiffer'];

    /**
     * @return array{text: string, title: ?string, author: ?string, domain: ?string}
     */
    public static function clean(string $raw): array
    {
        $text = str_replace(["\r\n", "\r", "\f", "\u{00A0}", "\u{00AD}", "\u{200B}"], ["\n", "\n", "\n", ' ', '', ''], $raw);
        $text = strtr($text, ['ﬁ' => 'fi', 'ﬂ' => 'fl', 'ﬀ' => 'ff', 'ﬃ' => 'ffi', 'ﬄ' => 'ffl', '’' => "'", '‘' => "'", '`' => "'"]);
        $text = self::restoreLigatures($text);

        // Lien markdown en ligne : « sur [www.site.fr](https://www.site.fr) » → « sur www.site.fr »
        $text = preg_replace('~\[([^\]\n]*)\]\(https?://[^)\s]*\)~u', '$1', $text);

        $meta = ['title' => null, 'author' => null, 'domain' => null];
        $header = null;
        $out = [];

        foreach (explode("\n", $text) as $line) {
            $line = trim(preg_replace('/[ \t]+/u', ' ', $line));

            if ($line === '') {
                $out[] = '';

                continue;
            }

            // En-tête d'impression : « Titre | Auteur https://site/... » (répété à chaque page)
            if (preg_match('~^(?<title>.+?)\s*\|\s*(?<author>[^|]*?)\s*(?:https?://(?:www\.)?(?<domain>[^/\s]+)\S*)?$~u', $line, $m) && preg_match('~https?://~i', $line)) {
                if ($header === null) {
                    $header = $line;
                    $meta = ['title' => trim($m['title']), 'author' => trim($m['author']) ?: null, 'domain' => $m['domain'] ?? null];
                }

                continue;
            }

            // Pied de page « Retrouvez toutes nos recettes sur www.site.fr » : on garde l'adresse comme source
            if (preg_match('~^(?:retrouvez|d[eé]couvrez|plus de recettes|toutes (?:nos|les) recettes|rendez-vous|rdv)\b.*?(?:https?://)?(?:www\.)?(?<domain>[a-z0-9][a-z0-9-]*(?:\.[a-z0-9-]+)*\.[a-z]{2,})\s*[.!]?$~iu', $line, $m)) {
                $meta['domain'] ??= mb_strtolower($m['domain']);

                continue;
            }

            // Adresse seule, lien markdown, date et heure d'impression, pied de page « 1 sur 2 05/10/2026, 11:50 »
            if (preg_match('~^(?:\[[^\]]*\]\(https?://[^)]*\)|https?://\S+|www\.\S+)$~iu', $line)
                || preg_match('~^\d+\s+(?:sur|/)\s+\d+(?:\s+\d{1,2}/\d{1,2}/\d{2,4}.*)?$~u', $line)
                || preg_match('~^\d{1,2}/\d{1,2}/\d{2,4},?\s+\d{1,2}:\d{2}$~', $line)
                || preg_match('~^(?:©|\(c\)|copyright)~iu', $line)) {
                continue;
            }

            $out[] = trim(preg_replace('~\s*https?://\S+~i', '', $line));
        }

        $cleaned = trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $out)));

        return ['text' => $cleaned] + $meta;
    }

    private static function restoreLigatures(string $text): string
    {
        if (! str_contains($text, "\u{FFFD}")) {
            return $text;
        }

        // « di�cile » → « difficile » : on teste les mots connus avant le cas général « fi »
        $text = preg_replace_callback('/\p{L}*\x{FFFD}\p{L}*/u', function (array $m) {
            foreach (self::FFI_WORDS as $word) {
                $pattern = '/^'.str_replace('ffi', "\u{FFFD}", preg_quote($word, '/')).'$/iu';
                if (preg_match($pattern, $m[0])) {
                    return str_replace("\u{FFFD}", 'ffi', $m[0]);
                }
            }

            return str_replace("\u{FFFD}", 'fi', $m[0]);
        }, $text);

        return str_replace("\u{FFFD}", 'fi', $text);
    }
}
