<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by BelongsToShop's global scope when a tenant-owned query runs with no
 * TenantContext set (and not inside withoutTenancy()). This is ADR-002 Option C:
 * a forgotten tenant filter is a loud 500 in CI, never a silent all-shops read.
 */
class TenantNotSetException extends RuntimeException
{
    public function __construct(string $model)
    {
        parent::__construct(
            "No shop is set in TenantContext for a query on [{$model}]. A tenant-owned "
            .'query ran outside a resolved tenant — set the context (or wrap it in '
            .'TenantContext::withoutTenancy()) rather than leaking every shop\'s rows.'
        );
    }
}
