<?php

namespace App\Filament\Studio\Resources\StudioAuditEvents\Tables;

use App\Enums\AuditAction;
use App\Models\StudioAuditEvent;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class StudioAuditEventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('action')->badge()->sortable(),
                TextColumn::make('actor.name')->label('Operator')->default('—')->searchable(),
                TextColumn::make('shop.name')->label('Shop')->default('—')->searchable(),
                TextColumn::make('subject')
                    ->label('Subject')
                    ->state(fn (StudioAuditEvent $record): string => $record->subject_type === null
                        ? '—'
                        : class_basename($record->subject_type).' #'.$record->subject_id),
                TextColumn::make('ip_address')->label('IP')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('shop_id')
                    ->label('Shop')
                    ->relationship('shop', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('action')->options(AuditAction::class),
            ]);
    }
}
