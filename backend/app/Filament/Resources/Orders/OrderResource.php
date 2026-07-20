<?php

namespace App\Filament\Resources\Orders;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Schemas\OrderForm;
use App\Filament\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use BackedEnum;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use UnitEnum;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'customer_name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['customer_name', 'phone', 'tracking_code'];
    }

    /**
     * Accept a website order: assign the schedule and move it to Pending.
     * Visible only while the order awaits confirmation — the one guided way in.
     */
    public static function acceptAction(): Action
    {
        return Action::make('accept')
            ->label('Accept')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (Order $record): bool => $record->status === OrderStatus::AwaitingConfirmation)
            ->requiresConfirmation()
            ->modalHeading('Accept order')
            ->modalDescription('Sets the decant schedule and moves the order to Pending.')
            ->modalSubmitActionLabel('Accept order')
            ->schema([
                DatePicker::make('decant_date')
                    ->required()
                    ->default(today()->addDay())
                    ->live()
                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                        if ($state) {
                            $set('delivery_date', Carbon::parse($state)->addDay()->toDateString());
                        }
                    }),
                DatePicker::make('delivery_date')
                    ->required()
                    ->default(today()->addDays(2))
                    ->afterOrEqual('decant_date'),
            ])
            ->action(function (Order $record, array $data): void {
                try {
                    $record->accept(Carbon::parse($data['decant_date']), Carbon::parse($data['delivery_date']));
                } catch (ValidationException $exception) {
                    // The bottle-stock guard. Its message is keyed to 'items', which has
                    // no field in this modal to render on — surface it as a notification.
                    Notification::make()
                        ->danger()
                        ->title('Not enough left in the bottle')
                        ->body(collect($exception->errors())->flatten()->implode(' '))
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Order accepted — added to the decant schedule.')
                    ->send();
            })
            ->after(fn (Order $record, Component $livewire) => self::refreshEditPage($record, $livewire));
    }

    /**
     * Reject a website order: keeps the row for record-keeping, stores the reason.
     */
    public static function rejectAction(): Action
    {
        $reasons = ['Out of stock', 'Unreachable address', 'Duplicate order', 'Other'];

        return Action::make('reject')
            ->label('Reject')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Order $record): bool => $record->status === OrderStatus::AwaitingConfirmation)
            ->requiresConfirmation()
            ->modalHeading('Reject order')
            ->modalDescription('The order is kept for your records — it just never enters the schedule.')
            ->modalSubmitActionLabel('Reject order')
            ->schema([
                Select::make('reason')
                    ->options(array_combine($reasons, $reasons))
                    ->required()
                    ->live(),
                Textarea::make('other_reason')
                    ->label('Tell the customer why')
                    ->visible(fn (Get $get): bool => $get('reason') === 'Other')
                    ->required(fn (Get $get): bool => $get('reason') === 'Other'),
            ])
            ->action(function (Order $record, array $data): void {
                $record->reject($data['reason'] === 'Other' ? $data['other_reason'] : $data['reason']);

                Notification::make()
                    ->success()
                    ->title('Order rejected.')
                    ->send();
            })
            ->after(fn (Order $record, Component $livewire) => self::refreshEditPage($record, $livewire));
    }

    /**
     * Download this order's packing invoice as an A5 PDF — exportCsv's
     * streamDownload mechanism with PDF bytes instead of CSV rows. Rendered
     * fresh on every click, never cached, so it always shows the order as-is.
     * Only fulfillable orders (pending/decanted/delivered) have anything worth
     * invoicing; neither invoice action changes state, so no refreshEditPage().
     */
    public static function downloadInvoiceAction(): Action
    {
        return Action::make('downloadInvoice')
            ->label('Download invoice')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->visible(fn (Order $record): bool => $record->status->isFulfillable())
            ->action(function (Order $record) {
                $record->loadMissing('items');

                // Render before streaming: a dompdf failure surfaces as an
                // action error instead of a corrupt half-downloaded file.
                $pdf = Pdf::loadView('pdf.invoice', ['order' => $record])->setPaper('a5')->output();

                return response()->streamDownload(
                    function () use ($pdf): void {
                        echo $pdf;
                    },
                    "invoice-{$record->tracking_code}.pdf",
                    ['Content-Type' => 'application/pdf'],
                );
            });
    }

    /**
     * Open the invoice inline in a new tab, ready for Ctrl+P. A plain link to
     * the panel-authenticated invoice route (see AdminPanelProvider) — the
     * Livewire action-closure mechanism can't open an inline document in a
     * new tab, so this one is a URL, not an action closure.
     */
    public static function printInvoiceAction(): Action
    {
        return Action::make('printInvoice')
            ->label('Print invoice')
            ->icon(Heroicon::OutlinedPrinter)
            ->visible(fn (Order $record): bool => $record->status->isFulfillable())
            ->url(fn (Order $record): string => route('filament.admin.orders.invoice', $record))
            ->openUrlInNewTab();
    }

    /**
     * After accepting/rejecting from the edit page, the loaded form still holds the old
     * status (with dates hidden) — saving it would silently revert the transition. Reload.
     */
    protected static function refreshEditPage(Order $record, Component $livewire): void
    {
        if ($livewire instanceof EditRecord) {
            $livewire->redirect(static::getUrl('edit', ['record' => $record]));
        }
    }

    public static function form(Schema $schema): Schema
    {
        return OrderForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrdersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'create' => CreateOrder::route('/create'),
            'edit' => EditOrder::route('/{record}/edit'),
        ];
    }
}
