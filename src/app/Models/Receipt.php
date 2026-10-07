<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Receipt extends Model
{
    public const STATUS_TO_REVIEW = 'a_valider';

    public const STATUS_DONE = 'traite';

    public const STATUS_IGNORED = 'ignore';

    public const STATUSES = [
        self::STATUS_TO_REVIEW => 'à valider',
        self::STATUS_DONE => 'traité',
        self::STATUS_IGNORED => 'ignoré',
    ];

    public const SOURCES = ['paperless' => 'Paperless', 'manuel' => 'saisie manuelle'];

    /** v0.19.0 : lecture par l'IA (mêmes états que les fiches de recettes). */
    public const VISION_TO_SEND = 'a_envoyer';

    public const VISION_PENDING = 'attente';

    public const VISION_DONE = 'lu';

    public const VISION_FAILED = 'echec';

    protected $fillable = [
        'household_id', 'shopping_list_id', 'source', 'paperless_document_id', 'paperless_modified_at', 'title', 'correspondent',
        'store_id', 'purchased_on', 'total_cents', 'expected_lines', 'unread_lines', 'raw_text', 'status', 'auto_applied', 'processed_at', 'processed_by',
        'vision_status', 'vision_error', 'vision_progress', 'vision_step', 'vision_started_at', 'vision',
    ];

    protected function casts(): array
    {
        return [
            'paperless_modified_at' => 'datetime',
            'purchased_on' => 'date',
            'total_cents' => 'integer',
            'expected_lines' => 'integer',
            'unread_lines' => 'array',
            'auto_applied' => 'boolean',
            'processed_at' => 'datetime',
            'vision_progress' => 'integer',
            'vision_started_at' => 'datetime',
            'vision' => 'array',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function shoppingList(): BelongsTo
    {
        return $this->belongsTo(ShoppingList::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ReceiptLine::class)->orderBy('position');
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /** Ticket envoyé à l'IA, en file ou en cours d'analyse. */
    public function isReading(): bool
    {
        return $this->vision_status === self::VISION_PENDING && \App\Support\RecipeScan\VisionClient::ready();
    }

    /** Ticket de Paperless que Louis peut envoyer (ou renvoyer) à l'IA : bouton « Envoyer à l'IA pour analyse ». */
    public function canBeSent(): bool
    {
        return $this->source === 'paperless' && $this->paperless_document_id !== null && $this->status === self::STATUS_TO_REVIEW
            && $this->vision_status !== self::VISION_PENDING && \App\Support\RecipeScan\VisionClient::ready();
    }

    /** Pas encore envoyé à l'IA : la page du ticket mène à la page de contrôle. */
    public function awaitsVision(): bool
    {
        return $this->vision_status === self::VISION_TO_SEND && $this->canBeSent();
    }

    /** Réponse du modèle (ticket recopié), relue par VisionReceiptParser. */
    public function visionAnswer(): ?array
    {
        $answer = $this->vision['reponse'] ?? null;

        return is_array($answer) ? $answer : null;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function label(): string
    {
        return $this->title ?: ($this->store?->name ?? $this->correspondent ?? 'Ticket').($this->purchased_on ? ' du '.$this->purchased_on->format('d/m/Y') : '');
    }

    /** Somme des lignes lues (après remises), pour contrôler la lecture par rapport au total du ticket. */
    public function linesTotalCents(): int
    {
        return (int) $this->lines->sum(fn (ReceiptLine $l) => $l->total_cents - $l->discount_cents);
    }

    public function paperlessUrl(): ?string
    {
        if (! $this->paperless_document_id || ! $this->household?->paperless_url) {
            return null;
        }

        return rtrim($this->household->paperless_url, '/').'/documents/'.$this->paperless_document_id.'/details';
    }
}
