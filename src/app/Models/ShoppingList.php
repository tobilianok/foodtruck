<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShoppingList extends Model
{
    protected $fillable = ['household_id', 'date_from', 'date_to', 'excluded_entry_ids', 'signature', 'revision', 'archived_at', 'stock_applied_at', 'created_by'];

    protected function casts(): array
    {
        return [
            'date_from' => 'date',
            'date_to' => 'date',
            'excluded_entry_ids' => 'array',
            'revision' => 'integer',
            'archived_at' => 'datetime',
            'stock_applied_at' => 'datetime',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ShoppingListItem::class)->orderBy('id');
    }

    /** Tickets de caisse rattachés à cette liste. */
    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class)->orderBy('purchased_on')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function days(): int
    {
        return $this->date_from->diffInDays($this->date_to) + 1;
    }

    /** « 3 au 9 octobre 2026 », « 28 septembre au 4 octobre 2026 ». */
    public function periodLabel(): string
    {
        $from = $this->date_from->copy()->locale('fr');
        $to = $this->date_to->copy()->locale('fr');

        if ($from->isSameDay($to)) {
            return $to->isoFormat('D MMMM YYYY');
        }

        return $from->isSameMonth($to)
            ? $from->isoFormat('D').' au '.$to->isoFormat('D MMMM YYYY')
            : $from->isoFormat('D MMMM').' au '.$to->isoFormat('D MMMM YYYY');
    }

    /** Budget des repas pour cette période : budget hebdomadaire au prorata du nombre de jours. */
    public function budgetCents(): int
    {
        return (int) round($this->household->weekly_budget_cents * $this->days() / 7);
    }

    public function bump(): void
    {
        $this->increment('revision');
    }
}
