<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Fiche de recette lue dans Paperless : à relire ou déjà transformée en recette (une fiche supprimée est effacée). */
class RecipeImport extends Model
{
    public const STATUS_TO_REVIEW = 'a_relire';

    public const STATUS_CREATED = 'cree';

    /**
     * Lecture par le modèle de vision (v0.18.0, envoi décidé par Louis fiche par fiche) : à envoyer, envoyée (en file
     * ou en cours d'analyse), lue, échec (erreur affichée, « Renvoyer à l'IA »).
     */
    public const LAYOUT_TO_SEND = 'a_envoyer';

    public const LAYOUT_PENDING = 'attente';

    public const LAYOUT_DONE = 'lu';

    public const LAYOUT_FAILED = 'echec';

    protected $fillable = [
        'household_id', 'paperless_document_id', 'paperless_modified_at', 'title', 'raw_text',
        'parsed', 'issues', 'status', 'recipe_id', 'auto_published', 'layout', 'layout_status', 'layout_error',
        'layout_progress', 'layout_step', 'layout_started_at', 'layout_pages',
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
            'layout_pages' => 'array',
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

    /** Fiche envoyée à l'IA : en file ou en cours d'analyse (s'il n'y a pas de modèle configuré, elle n'attend rien). */
    public function isReading(): bool
    {
        return $this->layout_status === self::LAYOUT_PENDING && \App\Support\RecipeScan\VisionClient::ready();
    }

    /** Fiche à envoyer à l'IA (pas encore envoyée, ou analyse ratée) : bouton « Envoyer à l'IA pour analyse ». */
    public function canBeSent(): bool
    {
        return $this->recipe_id === null && \App\Support\RecipeScan\VisionClient::ready()
            && in_array($this->layout_status, [self::LAYOUT_TO_SEND, self::LAYOUT_FAILED, null], true);
    }

    public function isToReview(): bool
    {
        return $this->status === self::STATUS_TO_REVIEW;
    }
}
