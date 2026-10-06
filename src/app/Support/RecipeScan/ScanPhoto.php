<?php

namespace App\Support\RecipeScan;

use App\Models\RecipeImport;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * v0.18.1 : photo du plat d'une fiche, découpée d'après le rectangle rendu par le modèle de vision.
 *
 * Le modèle situe la photo (coordonnées relatives 0-1000, ou pixels) ; le cadre est élargi un peu, puis resserré sur
 * les bords de la photo (les bandes presque blanches de la page sont retirées). La photo est gardée à part, sur le
 * disque public (imports/), jusqu'à la relecture : Louis la garde (elle devient la photo de la recette) ou non.
 */
class ScanPhoto
{
    private const MARGIN = 0.015;

    private const WHITE = 236;

    /** @param  array{x1: int, y1: int, x2: int, y2: int}  $box */
    public static function crop(string $pngBase64, array $box): ?\GdImage
    {
        $page = @imagecreatefromstring((string) base64_decode($pngBase64, true));
        if ($page === false) {
            return null;
        }
        $w = imagesx($page);
        $h = imagesy($page);
        $coords = [(int) ($box['x1'] ?? 0), (int) ($box['y1'] ?? 0), (int) ($box['x2'] ?? 0), (int) ($box['y2'] ?? 0)];
        [$sx, $sy] = max($coords) <= 1000 ? [$w / 1000, $h / 1000] : [1.0, 1.0];
        $x1 = (int) round(min($coords[0], $coords[2]) * $sx);
        $x2 = (int) round(max($coords[0], $coords[2]) * $sx);
        $y1 = (int) round(min($coords[1], $coords[3]) * $sy);
        $y2 = (int) round(max($coords[1], $coords[3]) * $sy);

        // Un peu de marge (le cadre du modèle est parfois juste trop serré), dans les limites de la page
        $mx = (int) round($w * self::MARGIN);
        $my = (int) round($h * self::MARGIN);
        $x1 = max(0, $x1 - $mx);
        $y1 = max(0, $y1 - $my);
        $x2 = min($w, $x2 + $mx);
        $y2 = min($h, $y2 + $my);

        // Puis resserré sur la photo : on retire les bandes presque blanches (fond de la page)
        while ($y2 - $y1 > 40 && self::white($page, $x1, $x2, $y1, true)) {
            $y1++;
        }
        while ($y2 - $y1 > 40 && self::white($page, $x1, $x2, $y2 - 1, true)) {
            $y2--;
        }
        while ($x2 - $x1 > 40 && self::white($page, $y1, $y2, $x1, false)) {
            $x1++;
        }
        while ($x2 - $x1 > 40 && self::white($page, $y1, $y2, $x2 - 1, false)) {
            $x2--;
        }

        if ($x2 - $x1 < 0.08 * $w || $y2 - $y1 < 0.08 * $h) {
            return null; // trop petit pour être la photo du plat
        }

        return imagecrop($page, ['x' => $x1, 'y' => $y1, 'width' => $x2 - $x1, 'height' => $y2 - $y1]) ?: null;
    }

    /** Ligne (ou colonne) presque blanche : moyenne de luminosité au-dessus du seuil, échantillonnée. */
    private static function white(\GdImage $image, int $from, int $to, int $at, bool $row): bool
    {
        $step = max(1, intdiv($to - $from, 200));
        $sum = 0;
        $n = 0;
        for ($i = $from; $i < $to; $i += $step) {
            $rgb = $row ? imagecolorat($image, $i, $at) : imagecolorat($image, $at, $i);
            $sum += (0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF));
            $n++;
        }

        return $n > 0 && $sum / $n >= self::WHITE;
    }

    /** Enregistre la photo proposée pour la fiche (remplace la précédente). @return string chemin sur le disque public */
    public static function store(RecipeImport $import, \GdImage $photo): string
    {
        self::delete($import);
        ob_start();
        imagejpeg($photo, null, 88);
        $path = 'imports/fiche-'.$import->id.'-'.Str::lower(Str::random(8)).'.jpg';
        Storage::disk('public')->put($path, (string) ob_get_clean());

        return $path;
    }

    public static function path(RecipeImport $import): ?string
    {
        $path = $import->layout['photo']['chemin'] ?? null;

        return is_string($path) && $path !== '' && Storage::disk('public')->exists($path) ? $path : null;
    }

    public static function url(RecipeImport $import): ?string
    {
        $path = self::path($import);

        return $path ? Storage::disk('public')->url($path) : null;
    }

    public static function delete(RecipeImport $import): void
    {
        $path = $import->layout['photo']['chemin'] ?? null;
        if (is_string($path) && $path !== '') {
            Storage::disk('public')->delete($path);
        }
    }
}
