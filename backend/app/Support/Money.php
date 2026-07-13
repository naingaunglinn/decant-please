<?php

namespace App\Support;

final class Money
{
    /**
     * Format whole Kyat for display: 90000 → "90,000 Ks".
     */
    public static function kyat(int $amount): string
    {
        return number_format($amount).' Ks';
    }
}
