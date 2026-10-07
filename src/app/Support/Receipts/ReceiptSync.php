<?php

namespace App\Support\Receipts;

use App\Models\Household;
use App\Models\Receipt;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Synchronisation des tickets Paperless d'un foyer : nouveaux documents importés et lus,
 * documents modifiés relus tant que le ticket n'est pas traité.
 *
 * v0.19.0 : avec le modèle de vision configuré, un nouveau ticket arrive « à envoyer à l'IA » : rien n'est lu ni
 * envoyé tant que Louis n'a pas cliqué « Envoyer à l'IA pour analyse » (un document à la fois). Le texte de Paperless
 * n'est plus utilisé pour lire les articles.
 *
 * v0.21.0 : le résumé dit combien de documents étiquetés le compte voit (un ticket absent est un problème de droits).
 */
class ReceiptSync
{
    public function __construct(private readonly ReceiptProcessor $processor = new ReceiptProcessor)
    {
    }

    /** @return array{new: int, updated: int, auto: int, to_send: int, visible: int, tag: ?string, error: ?string} */
    public function run(Household $household, ?PaperlessClient $client = null): array
    {
        $counts = ['new' => 0, 'updated' => 0, 'auto' => 0, 'to_send' => 0, 'visible' => 0, 'tag' => $household->paperlessTag(), 'error' => null];
        $vision = \App\Support\RecipeScan\VisionClient::ready();

        try {
            $client ??= PaperlessClient::for($household);
            $tagId = $client->tagId($household->paperlessTag());
            $correspondents = $client->correspondents();

            $documents = $client->documents($tagId);
            $counts['visible'] = count($documents);

            foreach ($documents as $document) {
                $id = (int) $document['id'];
                $modified = isset($document['modified']) ? Carbon::parse($document['modified']) : null;
                $receipt = Receipt::where('household_id', $household->id)->where('paperless_document_id', $id)->first();

                if ($receipt && ($receipt->status !== Receipt::STATUS_TO_REVIEW || ! $modified || $receipt->paperless_modified_at?->gte($modified))) {
                    continue;
                }

                $isNew = $receipt === null;
                $receipt ??= new Receipt(['household_id' => $household->id, 'source' => 'paperless', 'paperless_document_id' => $id]);

                $receipt->fill([
                    'paperless_modified_at' => $modified,
                    'title' => mb_substr((string) ($document['title'] ?? ''), 0, 200) ?: null,
                    'correspondent' => $correspondents[(int) ($document['correspondent'] ?? 0)] ?? $receipt->correspondent,
                    'raw_text' => (string) ($document['content'] ?? ''),
                ]);

                $created = $document['created_date'] ?? $document['created'] ?? null;
                if ($created && ! $receipt->purchased_on) {
                    $receipt->purchased_on = Carbon::parse($created)->toDateString();
                }

                if ($vision) {
                    // Rien n'est lu ni envoyé : Louis envoie chaque ticket à l'IA lui-même (déjà lu : on garde sa lecture)
                    if ($isNew || $receipt->vision_status === null) {
                        $receipt->vision_status = Receipt::VISION_TO_SEND;
                    }
                    $receipt->save();
                    $isNew ? $counts['new']++ : $counts['updated']++;
                    if ($receipt->vision_status === Receipt::VISION_TO_SEND) {
                        $counts['to_send']++;
                    }

                    continue;
                }

                // Relecture complète : total et magasin recalculés
                if (! $isNew) {
                    $receipt->total_cents = null;
                }
                $receipt->save();

                $this->processor->ingest($receipt);
                $isNew ? $counts['new']++ : $counts['updated']++;
            }

            $household->forceFill(['paperless_synced_at' => now(), 'paperless_last_error' => null])->save();
        } catch (Throwable $e) {
            report($e);
            $counts['error'] = $e->getMessage();
            $household->forceFill(['paperless_last_error' => mb_substr($e->getMessage(), 0, 500)])->save();
        }

        return $counts;
    }

    public static function summary(array $counts): string
    {
        if ($counts['error']) {
            return 'Synchronisation impossible : '.$counts['error'];
        }

        if ($counts['new'] + $counts['updated'] === 0) {
            // v0.21.0 : un ticket étiqueté mais absent est presque toujours un ticket que le compte Paperless de
            // Foodtruck n'a pas le droit de voir (étiquette posée après l'import, modification en masse…)
            if (! array_key_exists('visible', $counts)) {
                return 'Aucun nouveau ticket dans Paperless.';
            }
            $visible = $counts['visible'];

            return 'Aucun nouveau ticket dans Paperless ('.$visible.' document'.($visible > 1 ? 's' : '').' visible'.($visible > 1 ? 's' : '')
                .' avec l\'étiquette « '.($counts['tag'] ?? '').' »). Un ticket manque ? Foodtruck n\'a sans doute pas le droit de le voir : dans Paperless, ouvre le ticket, onglet « Permissions », et ajoute l\'utilisateur foodtruck dans « Afficher ».';
        }

        $parts = [];
        if ($counts['new']) {
            $parts[] = $counts['new'].' nouveau'.($counts['new'] > 1 ? 'x' : '').' ticket'.($counts['new'] > 1 ? 's' : '');
        }
        if ($counts['updated']) {
            $parts[] = $counts['updated'].' relu'.($counts['updated'] > 1 ? 's' : '');
        }
        $message = ucfirst(implode(', ', $parts)).'.';
        if (($counts['to_send'] ?? 0) > 0) {
            $message .= ' '.$counts['to_send'].' ticket'.($counts['to_send'] > 1 ? 's' : '').' à envoyer à l\'IA : bouton « Envoyer à l\'IA pour analyse », un à la fois.';
        }

        return $message;
    }
}
