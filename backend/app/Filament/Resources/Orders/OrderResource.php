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
                $record->accept(Carbon::parse($data['decant_date']), Carbon::parse($data['delivery_date']));

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
