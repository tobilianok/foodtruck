<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Photos de recettes : redressées (orientation EXIF des téléphones), redimensionnées,
 * compressées (WebP, ou JPEG à défaut) et stockées sur le disque « public ».
 */
class RecipePhoto
{
    public const MAX_SIZE = 1600;

    public const THUMB_SIZE = 640;

    /** @return array{photo_path: string, thumb_path: string} */
    public static function store(UploadedFile $file, string $slug): array
    {
        $image = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if ($image === false) {
            throw new RuntimeException('Image illisible.');
        }

        $image = self::orient($image, $file);
        $name = 'recettes/'.Str::limit($slug, 80, '').'-'.Str::lower(Str::random(8));

        $photo = self::save(self::resize($image, self::MAX_SIZE), $name);
        $thumb = self::save(self::resize($image, self::THUMB_SIZE), $name.'-mini');

        return ['photo_path' => $photo, 'thumb_path' => $thumb];
    }

    public static function delete(?string ...$paths): void
    {
        foreach (array_filter($paths) as $path) {
            Storage::disk('public')->delete($path);
        }
    }

    /** Copie des fichiers d'une recette (duplication). */
    public static function copy(?string $path, string $slug): ?string
    {
        if (! $path || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $target = 'recettes/'.Str::limit($slug, 80, '').'-'.Str::lower(Str::random(8)).'.'.pathinfo($path, PATHINFO_EXTENSION);
        Storage::disk('public')->copy($path, $target);

        return $target;
    }

    private static function orient(\GdImage $image, UploadedFile $file): \GdImage
    {
        if (! function_exists('exif_read_data') || ! in_array($file->getMimeType(), ['image/jpeg', 'image/jpg'], true)) {
            return $image;
        }

        $exif = @exif_read_data($file->getRealPath());
        $angle = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        return $angle ? (imagerotate($image, $angle, 0) ?: $image) : $image;
    }

    private static function resize(\GdImage $image, int $max): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $ratio = min(1, $max / max($width, $height));
        $w = max(1, (int) round($width * $ratio));
        $h = max(1, (int) round($height * $ratio));

        $resized = imagecreatetruecolor($w, $h);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $w, $h, $width, $height);

        return $resized;
    }

    private static function save(\GdImage $image, string $name): string
    {
        ob_start();
        if (function_exists('imagewebp')) {
            imagewebp($image, null, 80);
            $path = $name.'.webp';
        } else {
            imagejpeg($image, null, 82);
            $path = $name.'.jpg';
        }
        $data = (string) ob_get_clean();

        Storage::disk('public')->put($path, $data);

        return $path;
    }
}
