<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('items'))
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('customer_name')
                    ->label('Customer')
                    ->searchable()
                    ->description(fn (Order $record): string => $record->phone),
                TextColumn::make('phone')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('order_from')
                    ->label('From')
                    ->badge()
                    ->color(fn (OrderSource $state): string => $state === OrderSource::Website ? 'info' : 'gray'),
                TextColumn::make('items_summary')
                    ->label('Items')
                    ->state(fn (Order $record): string => $record->items->count().' item(s)')
                    ->tooltip(fn (Order $record): string => $record->items
                        ->map(fn ($item) => "{$item->fragrance_name_snapshot} — {$item->size_ml}ml × {$item->quantity}")
                        ->implode("\n")),
                TextColumn::make('tracking_code')
                    ->label('Tracking')
                    ->fontFamily(FontFamily::Mono)
                    ->copyable()
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('decant_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('delivery_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('total_mmk')
                    ->label('Total')
                    ->formatStateUsing(fn (int $state): string => Money::kyat($state))
                    ->summarize(Sum::make()->formatStateUsing(fn ($state): string => Money::kyat((int) $state)))
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('order_from')
                    ->label('Source')
                    ->options(OrderSource::class),
            ])
            ->recordActions([
                OrderResource::acceptAction(),
                OrderResource::rejectAction(),
                OrderResource::printInvoiceAction(),
                OrderResource::downloadInvoiceAction(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                Action::make('exportCsv')
                    ->label('Export CSV')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->action(function (HasTable $livewire) {
                        // Respect whatever tab/filters/sort the decanter is looking at.
                        $orders = $livewire->getFilteredSortedTableQuery()
                            ->with('items')
                            ->get(); // ponytail: ->get(), switch to chunking if order counts ever hit tens of thousands

                        return response()->streamDownload(function () use ($orders): void {
                            $out = fopen('php://output', 'w');
                            fputcsv($out, ['Date', 'Customer', 'Phone', 'Source', 'Items', 'Decant date', 'Delivery date', 'Status', 'Total (Ks)']);

                            foreach ($orders as $order) {
                                fputcsv($out, [
                                    $order->created_at->format('Y-m-d'),
                                    $order->customer_name,
                                    $order->phone,
                                    $order->order_from->label(),
                                    $order->items
                                        ->map(fn ($item) => "{$item->fragrance_name_snapshot} {$item->size_ml}ml × {$item->quantity}")
                                        ->implode('; '),
                                    $order->decant_date?->format('Y-m-d'),
                                    $order->delivery_date?->format('Y-m-d'),
                                    $order->status->label(),
                                    $order->total_mmk,
                                ]);
                            }

                            fclose($out);
                        }, 'orders-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
                    }),
                Action::make('downloadInvoices')
                    ->label('Download invoices (PDF)')
                    ->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->action(function (HasTable $livewire) {
                        // Same mechanism as exportCsv: honor whatever tab/filters/sort
                        // is on screen — filter to Today's Deliveries, click, and one
                        // PDF covers that whole batch, one order per A5 page.
                        // Defensively keep only fulfillable orders: a bulk export is a
                        // worse place to discover an edge case than a single-row action.
                        $orders = $livewire->getFilteredSortedTableQuery()
                            ->whereIn('status', array_filter(OrderStatus::cases(), fn (OrderStatus $status): bool => $status->isFulfillable()))
                            ->with('items')
                            ->get();

                        if ($orders->isEmpty()) {
                            Notification::make()
                                ->warning()
                                ->title('Nothing to invoice in this view')
                                ->body('Invoices exist for pending, decanted and delivered orders only.')
                                ->send();

                            return null;
                        }

                        // Render before streaming — a dompdf failure surfaces as an
                        // action error instead of a corrupt half-downloaded file.
                        $pdf = Pdf::loadView('pdf.invoice', ['orders' => $orders])->setPaper('a5')->output();

                        return response()->streamDownload(
                            function () use ($pdf): void {
                                echo $pdf;
                            },
                            'invoices-'.now()->format('Y-m-d').'.pdf',
                            ['Content-Type' => 'application/pdf'],
                        );
                    }),
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
