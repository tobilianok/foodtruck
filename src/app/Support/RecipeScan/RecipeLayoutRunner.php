<?php

namespace App\Support\RecipeScan;

use App\Models\RecipeImport;
use App\Support\Receipts\PaperlessClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Lecture des scans en attente : téléchargement du document dans Paperless, lecture par foodtruck-ocr
 * (blocs avec leur position), puis analyse habituelle (ScanImporter). Lancé chaque minute par le planificateur,
 * en arrière-plan : une page prend de 10 à 40 secondes sur la VM.
 *
 * En cas d'échec (service arrêté, document illisible), la fiche est lue avec le texte de Paperless, comme avant,
 * et la raison est affichée à la relecture ; « Relire la fiche » relance la lecture du scan.
 */
class RecipeLayoutRunner
{
    private const LOCK = 'foodtruck:lecture-fiches';

    public function __construct(private readonly ScanImporter $importer = new ScanImporter)
    {
    }

    /**
     * @param  int|null  $seconds  temps maximal (null : toutes les fiches en attente)
     * @return array{read: int, failed: int, left: int, busy: bool}
     */
    public function processPending(?int $seconds = 50, ?OcrClient $ocr = null): array
    {
        $counts = ['read' => 0, 'failed' => 0, 'left' => 0, 'busy' => false];
        $lock = Cache::lock(self::LOCK, 900);
        if (! $lock->get()) {
            $counts['busy'] = true;

            return $counts;
        }

        try {
            $started = microtime(true);
            $pending = RecipeImport::with('household')->where('layout_status', RecipeImport::LAYOUT_PENDING)->orderBy('id')->get();

            foreach ($pending as $i => $import) {
                if ($seconds !== null && $i > 0 && microtime(true) - $started > $seconds) {
                    $counts['left'] = $pending->count() - $i;
                    break;
                }
                $this->process($import, $ocr) ? $counts['read']++ : $counts['failed']++;
            }
        } finally {
            $lock->release();
        }

        return $counts;
    }

    /** Lit le scan d'une fiche puis l'analyse. Renvoie false si le texte de Paperless a dû servir. */
    public function process(RecipeImport $import, ?OcrClient $ocr = null): bool
    {
        $ok = true;
        try {
            $file = PaperlessClient::for($import->household)->download((int) $import->paperless_document_id);
            $layout = ($ocr ?? OcrClient::make())->read($file['body'], $file['mime'], $import->title);
            $import->forceFill(['layout' => $layout, 'layout_status' => RecipeImport::LAYOUT_DONE, 'layout_error' => null]);
        } catch (Throwable $e) {
            report($e);
            $ok = false;
            $import->forceFill(['layout_status' => RecipeImport::LAYOUT_FAILED, 'layout_error' => Str::limit($e->getMessage(), 250)]);
        }

        $import->save();
        if ($import->recipe_id === null) {
            $this->importer->ingest($import, $import->household);
        }

        return $ok;
    }

    /** Remet en lecture les fiches encore à relire (après l'installation du service, par exemple). */
    public static function queueAllToReview(): int
    {
        return RecipeImport::where('status', RecipeImport::STATUS_TO_REVIEW)->whereNull('recipe_id')
            ->update(['layout_status' => RecipeImport::LAYOUT_PENDING, 'layout_error' => null]);
    }
}
