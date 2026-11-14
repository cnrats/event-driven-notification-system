<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * One clock and one storage format for every timestamp the delivery pipeline
 * compares (leases, due times). The application clock is used everywhere, never
 * MySQL's NOW(), so a comparison never mixes two clocks.
 */
final class DbTime
{
    public const string FORMAT = 'Y-m-d H:i:s.v';

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }

    /**
     * Millisecond-precision string for a query binding. Binding a Carbon
     * instance directly would be formatted by the grammar without the
     * fractional part.
     */
    public static function format(DateTimeInterface $time): string
    {
        return $time->format(self::FORMAT);
    }
}
