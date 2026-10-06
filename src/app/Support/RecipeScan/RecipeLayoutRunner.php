<?php

namespace App\Support\RecipeScan;

use App\Models\RecipeImport;
use App\Support\Receipts\PaperlessClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Lecture des fiches en attente, une à la fois, lancée chaque minute par le planificateur (en arrière-plan) :
 * téléchargement de l'original dans Paperless → pages en images (foodtruck-pages) → lecture par le modèle de vision
 * (VisionClient, Ollama sur le PC de Louis) → analyse habituelle (ScanImporter). L'avancement est enregistré sur la
 * fiche (barre de progression de « Fiches Paperless »).
 *
 * v0.18.0 : plus aucun autre outil de reconnaissance de texte.
 * - Ollama injoignable (PC éteint, Ollama arrêté pendant une partie) : la fiche attend, nouvel essai toutes les
 *   15 minutes, sans limite (ce n'est pas un échec de lecture).
 * - Lecture ratée (réponse illisible, coupure) : nouvel essai 5, 10, 20, 40 puis 60 min plus tard ; après 6 échecs,
 *   la fiche attend « Relire la fiche », l'erreur est affichée.
 */
class RecipeLayoutRunner
{
    private const LOCK = 'foodtruck:lecture-fiches';

    /** Une lecture dure environ une minute ; 30 minutes au plus (délai de VisionClient) : verrou d'une heure. */
    private const LOCK_SECONDS = 3600;

    /** Moyennes des lectures précédentes (estimation de la barre de progression). */
    private const STATS = 'foodtruck:vision:moyennes';

    public const MAX_ATTEMPTS = 6;

    private array $lastStep = [];

    public function __construct(private readonly ScanImporter $importer = new ScanImporter)
    {
    }

    /**
     * @param  int|null  $seconds  temps maximal (null : toutes les fiches en attente) ; une fiche commencée va à son terme
     * @return array{read: int, failed: int, left: int, busy: bool}
     */
    public function processPending(?int $seconds = 50, ?PagesClient $pages = null, ?VisionClient $vision = null): array
    {
        $counts = ['read' => 0, 'failed' => 0, 'left' => 0, 'busy' => false];
        $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);
        if (! $lock->get()) {
            $counts['busy'] = true;

            return $counts;
        }

        try {
            $started = microtime(true);
            $pending = RecipeImport::with('household')->where('layout_status', RecipeImport::LAYOUT_PENDING)
                ->where(fn ($q) => $q->whereNull('layout_retry_at')->orWhere('layout_retry_at', '<=', now()))
                ->orderBy('id')->get();

            foreach ($pending as $i => $import) {
                if ($seconds !== null && $i > 0 && microtime(true) - $started > $seconds) {
                    $counts['left'] = $pending->count() - $i;
                    break;
                }
                $this->process($import, $pages, $vision) ? $counts['read']++ : $counts['failed']++;
            }
        } finally {
            $lock->release();
        }

        return $counts;
    }

    /** Lit une fiche avec le modèle de vision puis l'analyse. Renvoie false si la lecture a échoué (elle sera retentée). */
    public function process(RecipeImport $import, ?PagesClient $pages = null, ?VisionClient $vision = null): bool
    {
        $pages ??= PagesClient::make();
        $vision ??= VisionClient::make();
        $import->forceFill(['layout_started_at' => now(), 'layout_attempts' => (int) $import->layout_attempts + 1])->save();

        try {
            $this->step($import, 2, 'Téléchargement de la fiche depuis Paperless', true);
            $file = PaperlessClient::for($import->household)->download((int) $import->paperless_document_id);
            $layout = $this->read($import, $file, $pages, $vision);
        } catch (VisionUnavailable $e) {
            $import->forceFill([
                'layout_status' => RecipeImport::LAYOUT_PENDING, 'layout_error' => $e->getMessage(),
                'layout_attempts' => max(0, (int) $import->layout_attempts - 1), 'layout_retry_at' => now()->addMinutes(15),
                'layout_progress' => null, 'layout_step' => null, 'layout_started_at' => null,
            ])->save();

            return false;
        } catch (Throwable $e) {
            report($e);
            $attempts = (int) $import->layout_attempts;
            $last = $attempts >= self::MAX_ATTEMPTS;
            $import->forceFill([
                'layout_status' => $last ? RecipeImport::LAYOUT_FAILED : RecipeImport::LAYOUT_PENDING,
                'layout_error' => Str::limit($e->getMessage(), 250),
                'layout_retry_at' => $last ? null : now()->addMinutes(min(60, 5 * 2 ** ($attempts - 1))),
                'layout_progress' => null, 'layout_step' => null, 'layout_started_at' => null,
            ])->save();
            if ($last && $import->recipe_id === null) {
                $this->importer->ingest($import, $import->household);
            }

            return false;
        }

        $this->step($import, 98, 'Vérification des ingrédients et des unités', true);
        $import->forceFill([
            'layout' => $layout, 'layout_status' => RecipeImport::LAYOUT_DONE, 'layout_error' => null,
            'layout_progress' => null, 'layout_step' => null, 'layout_started_at' => null, 'layout_retry_at' => null,
        ])->save();
        if ($import->recipe_id === null) {
            $this->importer->ingest($import, $import->household);
        }

        return true;
    }

    /**
     * Lecture par le modèle de vision, avec l'avancement :
     * 5 % préparation des pages, 6 → 12 % envoi, 12 → 50 % lecture des images (d'après la durée des lectures
     * précédentes), 50 → 97 % écriture de la recette (d'après la longueur des réponses précédentes).
     */
    private function read(RecipeImport $import, array $file, PagesClient $pagesClient, VisionClient $vision): array
    {
        $this->step($import, 5, 'Préparation des pages', true);
        $pages = $pagesClient->pages($file['body'], $file['mime'], (int) config('foodtruck.vision_dpi', 200));

        $stats = Cache::get(self::STATS, []) + ['attente_page' => 15.0, 'octets' => 160000.0];
        $expectedWait = max(30.0, (float) $stats['attente_page']) * count($pages);
        $expectedBytes = max(20000.0, (float) $stats['octets']);
        $label = count($pages) > 1 ? count($pages).' pages' : '1 page';

        $result = $vision->read($pages, function (array $p) use ($import, $expectedWait, $expectedBytes, $label) {
            if ($p['receiving']) {
                $this->step($import, 50 + (int) round(47 * min(0.98, $p['received'] / $expectedBytes)), 'Écriture de la recette par le modèle');
            } elseif ($p['sent'] < 1) {
                $this->step($import, 6 + (int) round(6 * $p['sent']), "Envoi de la fiche ({$label}) à Ollama");
            } else {
                $this->step($import, 12 + (int) round(38 * min(0.98, $p['waited'] / $expectedWait)), "Lecture des images par le modèle ({$label})");
            }
        });

        // Moyennes mises à jour (barre de progression des lectures suivantes)
        Cache::forever(self::STATS, [
            'attente_page' => round(0.6 * $result['attente'] / count($pages) + 0.4 * (float) $stats['attente_page'], 1),
            'octets' => round(0.6 * $result['octets'] + 0.4 * (float) $stats['octets']),
        ]);

        return ['version' => 2, 'source' => 'vision', 'pages' => count($pages)] + $result;
    }

    /** Avancement enregistré au plus toutes les 2 secondes (la page « Fiches Paperless » le lit toutes les 3 secondes). */
    private function step(RecipeImport $import, int $percent, string $label, bool $force = false): void
    {
        $now = microtime(true);
        $last = $this->lastStep[$import->id] ?? null;
        if (! $force && $last !== null && $now - $last[0] < 2 && $last[1] === $label) {
            return;
        }
        $this->lastStep[$import->id] = [$now, $label];
        $percent = max(0, min(99, $percent));
        RecipeImport::whereKey($import->id)->update(['layout_progress' => $percent, 'layout_step' => Str::limit($label, 120, '')]);
        $import->layout_progress = $percent;
        $import->layout_step = $label;
        $import->syncOriginalAttributes(['layout_progress', 'layout_step']);
    }

    /** Remet en lecture les fiches encore à relire (après la mise en service du modèle, par exemple). */
    public static function queueAllToReview(): int
    {
        return RecipeImport::where('status', RecipeImport::STATUS_TO_REVIEW)->whereNull('recipe_id')
            ->update(['layout_status' => RecipeImport::LAYOUT_PENDING, 'layout_error' => null, 'layout_progress' => null,
                'layout_step' => null, 'layout_started_at' => null, 'layout_attempts' => 0, 'layout_retry_at' => null]);
    }
}
