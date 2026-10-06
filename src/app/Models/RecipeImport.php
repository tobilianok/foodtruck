<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Fiche de recette lue dans Paperless : à relire ou déjà transformée en recette (une fiche supprimée est effacée). */
class RecipeImport extends Model
{
    public const STATUS_TO_REVIEW = 'a_relire';

    public const STATUS_CREATED = 'cree';

    /** Lecture par le modèle de vision : à faire (ou à retenter), faite, impossible après plusieurs tentatives. */
    public const LAYOUT_PENDING = 'attente';

    public const LAYOUT_DONE = 'lu';

    public const LAYOUT_FAILED = 'echec';

    protected $fillable = [
        'household_id', 'paperless_document_id', 'paperless_modified_at', 'title', 'raw_text',
        'parsed', 'issues', 'status', 'recipe_id', 'auto_published', 'layout', 'layout_status', 'layout_error',
        'layout_progress', 'layout_step', 'layout_started_at', 'layout_attempts', 'layout_retry_at',
    ];

    protected function casts(): array
    {
        return [
            'paperless_modified_at' => 'datetime',
            'parsed' => 'array',
            'layout' => 'array',
            'issues' => 'array',
            'auto_published' => 'boolean',
            'layout_progress' => 'integer',
            'layout_started_at' => 'datetime',
            'layout_attempts' => 'integer',
            'layout_retry_at' => 'datetime',
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

    /** Fiche en attente de lecture par le modèle de vision (s'il n'est pas configuré, elle n'attend plus rien). */
    public function isReading(): bool
    {
        return $this->layout_status === self::LAYOUT_PENDING && \App\Support\RecipeScan\VisionClient::ready();
    }

    public function isToReview(): bool
    {
        return $this->status === self::STATUS_TO_REVIEW;
    }
}
