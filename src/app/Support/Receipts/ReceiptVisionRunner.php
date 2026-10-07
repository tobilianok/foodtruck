<?php

namespace App\Support\Receipts;

use App\Models\Receipt;
use App\Support\RecipeScan\PagesClient;
use App\Support\RecipeScan\RecipeLayoutRunner;
use App\Support\RecipeScan\VisionClient;
use App\Support\RecipeScan\VisionUnavailable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * v0.19.0 : analyse des tickets envoyés à l'IA par Louis (bouton « Envoyer à l'IA pour analyse », un document à la
 * fois), lancée chaque minute par le planificateur (foodtruck:lire-fiches, après les fiches de recettes) :
 * téléchargement de l'original dans Paperless → images (foodtruck-pages en mode ticket : les tickets longs sont
 * découpés en morceaux lisibles) → lecture par le modèle (VisionClient::readReceipt) → interprétation et contrôles
 * (VisionReceiptParser) → rapprochement des articles (ReceiptProcessor). Le ticket reste « à valider » : Louis le
 * valide toujours lui-même. Aucun nouvel essai automatique : en cas d'échec, l'erreur est affichée sur le ticket.
 */
class ReceiptVisionRunner
{
    /** Moyennes des lectures précédentes (estimation de la barre de progression). */
    private const STATS = 'foodtruck:vision:moyennes-tickets';

    private array $lastStep = [];

    public function __construct(private readonly ReceiptProcessor $processor = new ReceiptProcessor)
    {
    }

    /** @return array{read: int, failed: int, left: int, busy: bool} */
    public function processPending(?int $seconds = 50, ?PagesClient $pages = null, ?VisionClient $vision = null): array
    {
        $counts = ['read' => 0, 'failed' => 0, 'left' => 0, 'busy' => false];
        $lock = Cache::lock(RecipeLayoutRunner::LOCK, 3600);
        if (! $lock->get()) {
            $counts['busy'] = true;

            return $counts;
        }

        try {
            $started = microtime(true);
            $pending = Receipt::with('household')->where('vision_status', Receipt::VISION_PENDING)->orderBy('updated_at')->orderBy('id')->get();
            foreach ($pending as $i => $receipt) {
                if ($seconds !== null && $i > 0 && microtime(true) - $started > $seconds) {
                    $counts['left'] = $pending->count() - $i;
                    break;
                }
                // Pris en charge d'un coup (un envoi annulé entre-temps n'est pas touché ; plus d'annulation possible ensuite)
                if (Receipt::whereKey($receipt->id)->where('vision_status', Receipt::VISION_PENDING)->whereNull('vision_progress')->update(['vision_progress' => 1]) === 0) {
                    continue;
                }
                $this->process($receipt, $pages, $vision) ? $counts['read']++ : $counts['failed']++;
            }
        } finally {
            $lock->release();
        }

        return $counts;
    }

    /** Lit un ticket avec le modèle de vision puis rapproche ses articles. Renvoie false si la lecture a échoué. */
    public function process(Receipt $receipt, ?PagesClient $pages = null, ?VisionClient $vision = null): bool
    {
        $pages ??= PagesClient::make();
        $vision ??= VisionClient::make();
        $receipt->forceFill(['vision_started_at' => now()])->save();

        try {
            $this->step($receipt, 2, 'Téléchargement du ticket depuis Paperless', true);
            $file = PaperlessClient::for($receipt->household)->download((int) $receipt->paperless_document_id);
            $result = $this->read($receipt, $file, $pages, $vision);

            $this->step($receipt, 98, 'Vérification des calculs et rapprochement des articles', true);
            $receipt->forceFill([
                'vision' => $result, 'vision_status' => Receipt::VISION_DONE, 'vision_error' => null,
                'vision_progress' => null, 'vision_step' => null, 'vision_started_at' => null,
                // Relecture complète : total, date et magasin repris de la lecture
                'total_cents' => null,
            ]);
            \Illuminate\Support\Facades\DB::transaction(function () use ($receipt) {
                $receipt->save();
                $this->processor->ingest($receipt);
            });
        } catch (Throwable $e) {
            if (! $e instanceof VisionUnavailable) {
                report($e);
            }
            // État relu en base : la lecture ratée ne laisse rien à moitié (transaction annulée)
            if (! Receipt::whereKey($receipt->id)->exists()) {
                return false; // ticket supprimé pendant la lecture (./ft vider-tickets)
            }
            $receipt->refresh()->forceFill([
                'vision_status' => Receipt::VISION_FAILED, 'vision_error' => Str::limit($e->getMessage(), 250),
                'vision_progress' => null, 'vision_step' => null, 'vision_started_at' => null,
            ])->save();

            return false;
        }

        return true;
    }

    /** Lecture par le modèle, avec l'avancement (mêmes étapes que les fiches de recettes). */
    private function read(Receipt $receipt, array $file, PagesClient $pagesClient, VisionClient $vision): array
    {
        $this->step($receipt, 5, 'Préparation des images du ticket', true);
        $images = $pagesClient->pages($file['body'], $file['mime'], (int) config('foodtruck.vision_dpi', 200), true);
        $label = count($images) > 1 ? count($images).' images' : '1 image';

        $stats = Cache::get(self::STATS, []) + ['attente_image' => 3.0, 'octets' => 120000.0];
        $expectedWait = max(10.0, (float) $stats['attente_image'] * count($images));
        $expectedBytes = max(20000.0, (float) $stats['octets']);

        $result = $vision->readReceipt($images, function (array $p) use ($receipt, $expectedWait, $expectedBytes, $label) {
            if ($p['receiving']) {
                $this->step($receipt, 50 + (int) round(47 * min(0.98, $p['received'] / $expectedBytes)), 'Recopie des lignes par le modèle');
            } elseif ($p['sent'] < 1) {
                $this->step($receipt, 6 + (int) round(6 * $p['sent']), "Envoi du ticket ({$label}) à Ollama");
            } else {
                $this->step($receipt, 12 + (int) round(38 * min(0.98, $p['waited'] / $expectedWait)), "Lecture du ticket par le modèle ({$label})");
            }
        });

        Cache::forever(self::STATS, [
            'attente_image' => round(0.6 * $result['attente'] / count($images) + 0.4 * (float) $stats['attente_image'], 1),
            'octets' => round(0.6 * $result['octets'] + 0.4 * (float) $stats['octets']),
        ]);

        return [
            'version' => 1, 'source' => 'vision', 'images' => count($images), 'lu_le' => now()->toIso8601String(),
            'modele' => $result['modele'], 'secondes' => $result['secondes'], 'jetons_lus' => $result['jetons_lus'],
            'jetons_ecrits' => $result['jetons_ecrits'], 'reponse' => $result['ticket'],
        ];
    }

    /** Avancement enregistré au plus toutes les 2 secondes (la page « Tickets » le lit toutes les 3 secondes). */
    private function step(Receipt $receipt, int $percent, string $label, bool $force = false): void
    {
        $now = microtime(true);
        $last = $this->lastStep[$receipt->id] ?? null;
        if (! $force && $last !== null && $now - $last[0] < 2 && $last[1] === $label) {
            return;
        }
        $this->lastStep[$receipt->id] = [$now, $label];
        $percent = max(0, min(99, $percent));
        Receipt::whereKey($receipt->id)->update(['vision_progress' => $percent, 'vision_step' => Str::limit($label, 120, '')]);
        $receipt->vision_progress = $percent;
        $receipt->vision_step = $label;
        $receipt->syncOriginalAttributes(['vision_progress', 'vision_step']);
    }
}
