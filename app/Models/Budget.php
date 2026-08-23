<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Budget extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'household_id',
        'month',
        'currency_code',
        'total_planned',
    ];

    protected function casts(): array
    {
        return [
            'month' => 'date',
            'total_planned' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BudgetItem::class);
    }

    /**
     * Calculate the total planned amount from budget items.
     *
     * The item-level planned amounts are the source of truth for
     * budget calculations.
     */
    public function totalPlanned(): float
    {
        return (float) $this->items()->sum('planned_amount');
    }

    /**
     * Calculate the total actual amount from budget items.
     */
    public function totalActual(): float
    {
        return (float) $this->items()->sum('actual_amount');
    }

    /**
     * Calculate the remaining budget amount.
     *
     * Remaining = total planned - total actual.
     */
    public function remaining(): float
    {
        return $this->totalPlanned() - $this->totalActual();
    }
}
