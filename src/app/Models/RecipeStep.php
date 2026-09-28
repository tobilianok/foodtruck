<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeStep extends Model
{
    public $timestamps = false;

    protected $fillable = ['recipe_id', 'position', 'body', 'timer_minutes', 'equipment_id'];

    protected function casts(): array
    {
        return ['timer_minutes' => 'integer'];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }
}
