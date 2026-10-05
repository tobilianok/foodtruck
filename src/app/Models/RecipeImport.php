<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Fiche de recette lue dans Paperless : à relire, déjà transformée en recette, ou ignorée. */
class RecipeImport extends Model
{
    public const STATUS_TO_REVIEW = 'a_relire';

    public const STATUS_CREATED = 'cree';

    public const STATUS_IGNORED = 'ignoree';

    protected $fillable = [
        'household_id', 'paperless_document_id', 'paperless_modified_at', 'title', 'raw_text',
        'parsed', 'issues', 'status', 'recipe_id', 'auto_published',
    ];

    protected function casts(): array
    {
        return [
            'paperless_modified_at' => 'datetime',
            'parsed' => 'array',
            'issues' => 'array',
            'auto_published' => 'boolean',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function isToReview(): bool
    {
        return $this->status === self::STATUS_TO_REVIEW;
    }
}
