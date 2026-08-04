<?php

namespace App\Filament\Resources\Expenses\Schemas;

use App\Enums\ExpenseCategory;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;

class ExpenseForm
{
    /**
     * Entry must take seconds — the whole fork bet is that expenses actually
     * get entered. Four fields, sensible defaults, no required note.
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                DatePicker::make('spent_on')
                    ->label('Date')
                    ->default(today())
                    ->required(),
                Select::make('category')
                    ->options(ExpenseCategory::class)
                    ->required()
                    ->helperText('Stock purchases are inventory — they show below the line on the P&L, never as an operating expense (the juice is expensed as COGS when it pours).'),
                TextInput::make('amount_mmk')
                    ->label('Amount')
                    ->mask(RawJs::make('$money($input, \'.\', \',\', 0)'))
                    ->stripCharacters(',')
                    ->numeric()
                    ->minValue(1)
                    ->suffix('Ks')
                    ->required(),
                TextInput::make('note')
                    ->maxLength(255)
                    ->placeholder('e.g. 100 vials from Mingalar Market'),
            ]);
    }
}
