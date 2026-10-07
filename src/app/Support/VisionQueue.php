<?php

namespace App\Support;

use App\Models\Receipt;
use App\Models\RecipeImport;
use Illuminate\Database\Eloquent\Model;

/**
 * v0.19.0 : un seul document à la fois chez Ollama (un seul PC), fiche de recette ou ticket de caisse, tous foyers
 * confondus. Les deux ont un numéro Paperless (paperless_document_id) affiché dans le message « une à la fois ».
 */
class VisionQueue
{
    public static function busy(?Model $except = null): RecipeImport|Receipt|null
    {
        $import = RecipeImport::where('layout_status', RecipeImport::LAYOUT_PENDING)
            ->when($except instanceof RecipeImport, fn ($q) => $q->whereKeyNot($except->getKey()))->orderBy('id')->first();
        if ($import) {
            return $import;
        }

        return Receipt::where('vision_status', Receipt::VISION_PENDING)
            ->when($except instanceof Receipt, fn ($q) => $q->whereKeyNot($except->getKey()))->orderBy('id')->first();
    }

    /** « la fiche Paperless n° 490 » / « le ticket Paperless n° 476 ». */
    public static function describe(RecipeImport|Receipt $item): string
    {
        return ($item instanceof Receipt ? 'le ticket' : 'la fiche').' Paperless n° '.$item->paperless_document_id;
    }
}
