<?php

namespace App\Support;

/**
 * The units pooled stock is counted in (step 40b): products.stock_unit, and a
 * template's measure(). One base unit per kind, stored as whole numbers — ml for
 * liquid, kyatthar for weight. A viss is 100 kyatthar and is display only, so a
 * 10-kyatthar pack and a 1-viss pack draw from the same running total with no
 * conversion. The one place an amount becomes words.
 */
final class StockUnit
{
    public const ML = 'ml';

    public const KYATTHAR = 'kyatthar';

    public const KYATTHAR_PER_VISS = 100;

    /** "30ml", "25 kyatthar", "1 viss", "1 viss 50 kyatthar"; a bare number for pieces (no unit). */
    public static function format(int $amount, ?string $unit): string
    {
        return match ($unit) {
            null, '' => (string) $amount,
            self::KYATTHAR => self::weight($amount),
            default => "{$amount}{$unit}",
        };
    }

    /** The admin hint beside an amount typed in $unit, or null when it needs none. */
    public static function help(?string $unit): ?string
    {
        return $unit === self::KYATTHAR ? '100 kyatthar = 1 viss.' : null;
    }

    private static function weight(int $kyatthar): string
    {
        $viss = intdiv($kyatthar, self::KYATTHAR_PER_VISS);
        $rest = $kyatthar % self::KYATTHAR_PER_VISS;

        return match (true) {
            $viss === 0 => "{$rest} kyatthar",
            $rest === 0 => "{$viss} viss",
            default => "{$viss} viss {$rest} kyatthar",
        };
    }
}
