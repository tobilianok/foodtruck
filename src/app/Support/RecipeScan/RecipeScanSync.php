<?php

namespace App\Support\RecipeScan;

use App\Models\Household;
use App\Models\RecipeImport;
use App\Support\Receipts\PaperlessClient;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Récupère dans Paperless les fiches portant l'étiquette « recettes », les lit et crée les recettes.
 * Une fiche déjà transformée en recette n'est jamais relue : ce qui a été relu à la main est conservé.
 */
class RecipeScanSync
{
    public function __construct(private readonly ScanImporter $importer = new ScanImporter)
    {
    }

    /** @return array{new: int, updated: int, published: int, drafts: int, to_review: int, empty: int, error: ?string} */
    public function run(Household $household, ?PaperlessClient $client = null): array
    {
        $counts = ['new' => 0, 'updated' => 0, 'published' => 0, 'drafts' => 0, 'to_review' => 0, 'empty' => 0, 'error' => null];

        try {
            $client ??= PaperlessClient::for($household);
            $tagId = $client->tagId($household->paperlessRecipeTag());

            foreach ($client->documents($tagId) as $document) {
                $id = (int) $document['id'];
                $modified = isset($document['modified']) ? Carbon::parse($document['modified']) : null;
                $content = trim((string) ($document['content'] ?? ''));

                // Document pas encore passé par la reconnaissance de texte : on réessaiera à la prochaine synchronisation
                if ($content === '') {
                    $counts['empty']++;

                    continue;
                }

                $import = RecipeImport::where('household_id', $household->id)->where('paperless_document_id', $id)->first();

                if ($import && ($import->status !== RecipeImport::STATUS_TO_REVIEW || $import->recipe_id || ! $modified || $import->paperless_modified_at?->gte($modified))) {
                    continue;
                }

                $isNew = $import === null;
                $import ??= new RecipeImport(['household_id' => $household->id, 'paperless_document_id' => $id]);
                $import->fill([
                    'paperless_modified_at' => $modified,
                    'title' => mb_substr((string) ($document['title'] ?? ''), 0, 200) ?: null,
                    'raw_text' => $content,
                ]);
                $import->save();

                $this->importer->ingest($import, $household);
                $isNew ? $counts['new']++ : $counts['updated']++;

                $import->refresh()->loadMissing('recipe');
                match (true) {
                    $import->recipe?->isPublished() === true => $counts['published']++,
                    $import->recipe !== null => $counts['drafts']++,
                    default => $counts['to_review']++,
                };
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
            return $counts['empty'] > 0
                ? "Aucune nouvelle fiche lisible ({$counts['empty']} document(s) sans texte : la reconnaissance de texte de Paperless n'est peut-être pas terminée)."
                : 'Aucune nouvelle fiche dans Paperless.';
        }

        $parts = [];
        if ($counts['published']) {
            $parts[] = $counts['published'].' recette'.($counts['published'] > 1 ? 's publiées' : ' publiée').' automatiquement';
        }
        if ($counts['drafts']) {
            $parts[] = $counts['drafts'].' en brouillon à relire';
        }
        if ($counts['to_review']) {
            $parts[] = $counts['to_review'].' fiche'.($counts['to_review'] > 1 ? 's' : '').' à compléter';
        }

        return ucfirst(implode(', ', $parts)).'.';
    }
}
