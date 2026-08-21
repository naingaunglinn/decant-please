<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Step 34 §3 — the kinds of studio-impersonation events recorded in
 * `studio_audit_events`: entering a shop's panel, taking control, and each
 * persisted write performed while in control.
 */
enum AuditAction: string implements HasColor, HasLabel
{
    case PanelEnter = 'panel.enter';
    case TakeControl = 'take_control';
    case WriteCreated = 'write.created';
    case WriteUpdated = 'write.updated';
    case WriteDeleted = 'write.deleted';

    public function getLabel(): string|Htmlable|null
    {
        return $this->label();
    }

    /**
     * @return string|array<string>|null
     */
    public function getColor(): string|array|null
    {
        return $this->color();
    }

    public function label(): string
    {
        return match ($this) {
            self::PanelEnter => 'Entered panel',
            self::TakeControl => 'Took control',
            self::WriteCreated => 'Created',
            self::WriteUpdated => 'Updated',
            self::WriteDeleted => 'Deleted',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PanelEnter => 'gray',
            self::TakeControl => 'warning',
            self::WriteCreated => 'success',
            self::WriteUpdated => 'info',
            self::WriteDeleted => 'danger',
        };
    }

    /** Map an Eloquent write event ('created'|'updated'|'deleted') to its action. */
    public static function forWrite(string $event): self
    {
        return match ($event) {
            'created' => self::WriteCreated,
            'updated' => self::WriteUpdated,
            'deleted' => self::WriteDeleted,
        };
    }
}
