<?php

namespace App\Filament\Studio\Resources\StudioAuditEvents;

use App\Filament\Studio\Resources\StudioAuditEvents\Pages\ListStudioAuditEvents;
use App\Filament\Studio\Resources\StudioAuditEvents\Tables\StudioAuditEventsTable;
use App\Models\StudioAuditEvent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Step 34 §3 — the studio impersonation audit log, on /studio only (the super-admin
 * home, not inside any shop). Read-only: the table is an append-only record, so there
 * is no create/edit/delete. Two gates, belt and braces: User::canAccessPanel keeps
 * non-studio users out of the whole panel, and canAccess() keeps this resource
 * studio-only even if it were ever re-registered on a tenant panel. Not tenant-scoped
 * — StudioAuditEvent is platform-owned and spans every shop, so the log reads
 * cross-shop with no withoutTenancy().
 */
class StudioAuditEventResource extends Resource
{
    protected static ?string $model = StudioAuditEvent::class;

    // Moot in the tenant-free studio panel, but a correct claim wherever this lands:
    // the audit log is platform-wide and must never be narrowed to a current tenant.
    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Audit log';

    protected static ?string $modelLabel = 'audit event';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasRole('studio_admin');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return StudioAuditEventsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStudioAuditEvents::route('/'),
        ];
    }
}
