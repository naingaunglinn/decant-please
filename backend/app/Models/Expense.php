<?php

namespace App\Models;

use App\Enums\ExpenseCategory;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['spent_on', 'category', 'amount_mmk', 'note'])]
class Expense extends Model
{
    use BelongsToShop;

    protected function casts(): array
    {
        return [
            'spent_on' => 'date',
            'category' => ExpenseCategory::class,
            'amount_mmk' => 'integer',
        ];
    }
}
