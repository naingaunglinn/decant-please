<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        return [
            'needs_review' => Tab::make('Needs review')
                ->badge(fn (): int => Order::where('status', OrderStatus::AwaitingConfirmation)->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', OrderStatus::AwaitingConfirmation)),
            'all' => Tab::make('All'),
            'todays_decants' => Tab::make("Today's Decants")
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereDate('decant_date', today())
                    ->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Rejected])),
            'todays_deliveries' => Tab::make("Today's Deliveries")
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereDate('delivery_date', today())
                    ->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Rejected])),
            'pending' => Tab::make('Pending')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', OrderStatus::Pending)),
            'decanted' => Tab::make('Decanted')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', OrderStatus::Decanted)),
            'delivered' => Tab::make('Delivered')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', OrderStatus::Delivered)),
            'rejected' => Tab::make('Rejected')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', OrderStatus::Rejected)),
            'cancelled' => Tab::make('Cancelled')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', OrderStatus::Cancelled)),
        ];
    }
}
