<?php

namespace App\Support\Receipts;

use App\Models\Household;
use App\Models\Receipt;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Synchronisation des tickets Paperless d'un foyer : nouveaux documents importés et lus,
 * documents modifiés relus tant que le ticket n'est pas traité.
 */
class ReceiptSync
{
    public function __construct(private readonly ReceiptProcessor $processor = new ReceiptProcessor)
    {
    }

    /** @return array{new: int, updated: int, auto: int, error: ?string} */
    public function run(Household $household, ?PaperlessClient $client = null): array
    {
        $counts = ['new' => 0, 'updated' => 0, 'auto' => 0, 'error' => null];

        try {
            $client ??= PaperlessClient::for($household);
            $tagId = $client->tagId($household->paperlessTag());
            $correspondents = $client->correspondents();

            foreach ($client->documents($tagId) as $document) {
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

                // Relecture complète : total et magasin recalculés
                if (! $isNew) {
                    $receipt->total_cents = null;
                }
                $receipt->save();

                $this->processor->ingest($receipt);
                $isNew ? $counts['new']++ : $counts['updated']++;
                if ($receipt->fresh()->auto_applied) {
                    $counts['auto']++;
                }
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
            return 'Aucun nouveau ticket dans Paperless.';
        }

        $parts = [];
        if ($counts['new']) {
            $parts[] = $counts['new'].' nouveau'.($counts['new'] > 1 ? 'x' : '').' ticket'.($counts['new'] > 1 ? 's' : '');
        }
        if ($counts['updated']) {
            $parts[] = $counts['updated'].' relu'.($counts['updated'] > 1 ? 's' : '');
        }
        if ($counts['auto']) {
            $parts[] = $counts['auto'].' traité'.($counts['auto'] > 1 ? 's' : '').' automatiquement';
        }

        return ucfirst(implode(', ', $parts)).'.';
    }
}
