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

    protected $fillable = [
        'household_id', 'source', 'paperless_document_id', 'paperless_modified_at', 'title', 'correspondent',
        'store_id', 'purchased_on', 'total_cents', 'raw_text', 'status', 'auto_applied', 'processed_at', 'processed_by',
    ];

    protected function casts(): array
    {
        return [
            'paperless_modified_at' => 'datetime',
            'purchased_on' => 'date',
            'total_cents' => 'integer',
            'auto_applied' => 'boolean',
            'processed_at' => 'datetime',
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

    public function lines(): HasMany
    {
        return $this->hasMany(ReceiptLine::class)->orderBy('position');
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
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
