<?php

namespace App\Services\Finance;

class Money
{
    public static function multiplyDivide(int $amount, int $multiplier, int $divisor): int
    {
        if ($amount < 0 || $multiplier < 0 || $divisor <= 0 || ($multiplier > 0 && $amount > intdiv(PHP_INT_MAX - intdiv($divisor, 2), $multiplier))) {
            throw new \InvalidArgumentException('Invalid or overflowing money calculation.');
        }

        return intdiv($amount * $multiplier + intdiv($divisor, 2), $divisor);
    }

    /** Largest-remainder allocation against remaining invoice components, never floats. */
    public static function allocate(int $amount, array $components): array
    {
        $total = 0;
        if ($components === []) {
            throw new \InvalidArgumentException('Refund components are required.');
        }
        foreach ($components as $value) {
            if (! is_int($value) || $value < 0 || $value > PHP_INT_MAX - $total) {
                throw new \InvalidArgumentException('Invalid refund components.');
            }
            $total += $value;
        }
        if ($amount < 0 || $amount > $total) {
            throw new \InvalidArgumentException('Invalid refund allocation.');
        }
        if ($total === 0) {
            return array_fill_keys(array_keys($components), 0);
        }
        $result = [];
        $remainders = [];
        foreach ($components as $key => $value) {
            if ($value > 0 && $amount > intdiv(PHP_INT_MAX, $value)) {
                throw new \InvalidArgumentException('Refund allocation overflow.');
            }$result[$key] = intdiv($amount * $value, $total);
            $remainders[$key] = ($amount * $value) % $total;
        }
        arsort($remainders, SORT_NUMERIC);
        $left = $amount - array_sum($result);
        foreach ($remainders as $key => $unused) {
            if ($left === 0) {
                break;
            }if ($result[$key] < $components[$key]) {
                $result[$key]++;
                $left--;
            }
        }
        if ($left !== 0) {
            throw new \LogicException('Could not allocate refund.');
        }

        return $result;
    }
}
