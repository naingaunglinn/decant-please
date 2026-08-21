<?php

namespace App\Filament\Studio\Resources\StudioAuditEvents\Pages;

use App\Filament\Studio\Resources\StudioAuditEvents\StudioAuditEventResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Read-only list — no header create action (the log is append-only, written only by
 * the impersonation listeners).
 */
class ListStudioAuditEvents extends ListRecords
{
    protected static string $resource = StudioAuditEventResource::class;
}
