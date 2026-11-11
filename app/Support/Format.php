<?php

namespace App\Support;

use DateTimeInterface;

final class Format
{
    private const SUFFIXES = [
        [1e30, 'N'], [1e27, 'O'], [1e24, 'Sp'], [1e21, 'S'], [1e18, 'QQ'],
        [1e15, 'Q'], [1e12, 'T'], [1e9, 'B'], [1e6, 'M'], [1e3, 'K'],
    ];

    public static function number(int|float $number): string
    {
        $negative = $number < 0;
        $number = abs($number);

        foreach (self::SUFFIXES as [$size, $suffix]) {
            if ($number >= $size) {
                return ($negative ? '-' : '') . round($number / $size, 1) . $suffix;
            }
        }

        return ($negative ? '-' : '') . $number;
    }

    public static function ago(string|DateTimeInterface $time): string
    {
        $diff = (new \DateTime())->diff($time instanceof DateTimeInterface ? $time : new \DateTime($time));

        foreach (['y' => 'year', 'm' => 'month', 'd' => 'day', 'h' => 'hour', 'i' => 'minute'] as $unit => $name) {
            if ($diff->$unit > 0) {
                return $diff->$unit . ' ' . $name . ($diff->$unit > 1 ? 's' : '') . ' ago';
            }
        }

        return 'just now';
    }

    public static function duration(float $seconds): string
    {
        $seconds = (int) round($seconds);
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        if ($days > 0) {
            return sprintf('%d:%02d:%02d:%02d', $days, $hours, $minutes, $secs);
        }

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
        }

        return sprintf('%d:%02d', $minutes, $secs);
    }
}
