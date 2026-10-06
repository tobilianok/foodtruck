<?php

namespace App\Support\RecipeScan;

use Illuminate\Support\Str;

/**
 * v0.18.0 : recette rendue par le modèle de vision (JSON imposé par VisionClient::SCHEMA) remise sous la forme de
 * texte que le lecteur habituel connaît (la même que LayoutComposer) :
 *
 *   Titre
 *   Pour 2 personnes
 *   Préparation : 40 min
 *
 *   Les ingrédients
 *   - 500 g Pommes de terre
 *   - ½ sachet Ciboulette
 *   À ajouter vous-même :
 *   - 1 cc Vinaigre de vin rouge ou de cidre
 *
 *   Conseil
 *   …
 *
 *   La recette
 *   Etape 1
 *   Coup d'envoi gourmand : Épluchez…
 *
 * Tout le reste (rapprochement avec les ingrédients, unités propres, conversions, lignes en rouge, fenêtres de
 * correction) est donc inchangé. Une ligne que le modèle signale difficile à lire passe en rouge (« ⚠ »).
 */
class VisionComposer
{
    public const DOUBT = 'Lecture incertaine sur la fiche : vérifie la quantité et le nom avec le scan.';

    private const FRACTIONS = ['0.25' => '¼', '0.33' => '⅓', '0.5' => '½', '0.67' => '⅔', '0.75' => '¾'];

    /** @param  array{titre?: string, personnes?: ?int, temps_minutes?: ?int, ingredients?: array, etapes?: array, conseil?: ?string, site?: ?string}  $recipe */
    public static function compose(array $recipe): string
    {
        $out = [];
        $title = self::clean($recipe['titre'] ?? '');
        if ($title !== '') {
            $out[] = $title;
            $out[] = '';
        }
        if (! empty($recipe['personnes']) && (int) $recipe['personnes'] > 0) {
            $out[] = 'Pour '.(int) $recipe['personnes'].' personnes';
        }
        if (! empty($recipe['temps_minutes']) && (int) $recipe['temps_minutes'] > 0) {
            $out[] = 'Préparation : '.(int) $recipe['temps_minutes'].' min';
        }

        $lines = [];
        $group = null;
        foreach ($recipe['ingredients'] ?? [] as $item) {
            $name = self::clean((string) ($item['nom'] ?? ''));
            if ($name === '') {
                continue;
            }
            $itemGroup = self::clean((string) ($item['groupe'] ?? ''));
            if ($itemGroup !== '' && $itemGroup !== $group) {
                $lines[] = rtrim($itemGroup, ' :').' :';
            }
            $group = $itemGroup !== '' ? $itemGroup : $group;
            $lines[] = '- '.self::ingredient($item, $name);
        }
        if ($lines !== []) {
            array_push($out, '', 'Les ingrédients', ...$lines);
        }

        // Étapes : puces retirées, phrases recollées en un paragraphe ; les encadrés « L'astuce du chef » que le modèle
        // laisse parfois dans l'étape rejoignent le conseil ; la consigne générale des cartes de kits est écartée
        $tips = [];
        $tip = self::clean((string) ($recipe['conseil'] ?? ''));
        if ($tip !== '') {
            $tips[] = $tip;
        }
        $steps = [];
        foreach ($recipe['etapes'] ?? [] as $step) {
            [$body, $stepTips] = self::stepBody((string) ($step['texte'] ?? ''));
            array_push($tips, ...$stepTips);
            if ($body === '') {
                continue;
            }
            $stepTitle = self::clean((string) ($step['titre'] ?? ''));
            $steps[] = $stepTitle !== '' ? rtrim($stepTitle, ' :').' : '.$body : $body;
        }

        $tips = array_values(array_unique(array_map(fn ($t) => preg_replace('/^l[’\'\s]*astuce du chef\s*:\s*/iu', '', $t), $tips)));
        if ($tips !== []) {
            array_push($out, '', 'Conseil', implode(' ', $tips));
        }
        if ($steps !== []) {
            $out[] = '';
            $out[] = 'La recette';
            foreach ($steps as $k => $body) {
                array_push($out, '', 'Etape '.($k + 1), $body);
            }
        }

        $site = self::clean((string) ($recipe['site'] ?? ''));
        if ($site !== '' && preg_match('/^(?:https?:\/\/)?((?:www\.)?[a-z0-9-]+(?:\.[a-z0-9-]+)+)/iu', $site, $m) === 1) {
            array_push($out, '', 'Retrouvez toutes nos recettes sur '.Str::lower($m[1]));
        }

        return trim(implode("\n", $out));
    }

    /** @return array{0: string, 1: array<int, string>} texte de l'étape en un paragraphe, encadrés « astuce » à part */
    private static function stepBody(string $text): array
    {
        $parts = [];
        $tips = [];
        foreach (preg_split('/\R+/u', $text) as $line) {
            $line = trim(preg_replace('/^[\s•·\-–*]+/u', '', $line));
            if ($line === '' || preg_match('/^veillez [àa] bien respecter les quantit/iu', $line) === 1) {
                continue;
            }
            if (preg_match('/^l[’\'\s]*astuce du chef\s*:/iu', $line) === 1) {
                $tips[] = self::clean($line);

                continue;
            }
            $parts[] = $line;
        }

        return [self::clean(implode(' ', $parts)), $tips];
    }

    /** « ½ sachet Ciboulette », « 2 Tomate » (pièces), « Poivre et sel selon votre goût ». */
    private static function ingredient(array $item, string $name): string
    {
        $quantity = isset($item['quantite']) && is_numeric($item['quantite']) && (float) $item['quantite'] > 0 ? (float) $item['quantite'] : null;
        // « sachet(s) », « pièce(s) » : unité au singulier
        $unit = self::clean(preg_replace('/\(s\)$/u', '', (string) ($item['unite'] ?? '')));
        $precision = self::clean((string) ($item['precision'] ?? ''));
        // La précision répète parfois l'unité ou la quantité : inutile
        if ($precision !== '' && ($quantity !== null && preg_match('/^\d/u', $precision) === 1)) {
            $precision = '';
        }

        $line = $name;
        if ($quantity !== null) {
            $unit = preg_match('/^(?:pi[eè]ces?|pcs?|unit[ée]s?)$/iu', $unit) === 1 ? '' : $unit;
            $line = trim(preg_replace('/\s+/u', ' ', self::quantity($quantity).' '.$unit.' '.$name));
        }
        if ($precision !== '') {
            $line .= ' '.$precision;
        }
        if (! empty($item['doute'])) {
            $line .= ' ⚠ '.self::DOUBT;
        }

        return $line;
    }

    /** 0.5 → ½, 1.5 → 1½, 2 → 2, 0.2 → 0,2. */
    private static function quantity(float $value): string
    {
        $whole = (int) floor($value + 1e-9);
        $rest = $value - $whole;
        if ($rest < 0.01) {
            return (string) $whole;
        }
        foreach (self::FRACTIONS as $decimal => $glyph) {
            if (abs($rest - (float) $decimal) < 0.02) {
                return ($whole > 0 ? $whole : '').$glyph;
            }
        }

        return str_replace('.', ',', rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.'));
    }

    private static function clean(string $text): string
    {
        // Retours à la ligne, astérisques (« Tomate* ») et espaces en trop
        $text = preg_replace('/(?<=\p{L})\*+/u', '', $text);

        return trim(preg_replace('/\s+/u', ' ', $text));
    }
}
