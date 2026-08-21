<?php

namespace App\Exceptions;

use Filament\Support\Exceptions\Halt;

/**
 * Step 34 §3 — thrown by the write guard when a studio operator attempts a write
 * while impersonating a shop read-only (before taking control).
 *
 * It extends Filament's Halt on purpose: Filament's action runner catches Halt and
 * halts the action gracefully (validated empirically — it survives even a custom
 * ->action() closure's try/catch and never becomes a 500). The guard sends a
 * Filament notification immediately before throwing, so the operator sees "take
 * control to edit" rather than a silent no-op or an error page.
 */
class ReadOnlyImpersonationException extends Halt {}
