<?php

namespace Tests\Unit;

use App\Services\Finance\Money;
use PHPUnit\Framework\TestCase;

class FinanceMoneyTest extends TestCase
{
    public function test_every_small_refund_preserves_units_component_bounds_and_full_reversal(): void
    {
        for ($tax = 0; $tax <= 3; $tax++) {
            for ($commission = 0; $commission <= 3; $commission++) {
                for ($vendor = 0; $vendor <= 3; $vendor++) {
                    $original = ['tax_minor' => $tax, 'commission_minor' => $commission, 'vendor_minor' => $vendor];
                    $total = array_sum($original);
                    for ($amount = 0; $amount <= $total; $amount++) {
                        $allocation = Money::allocate($amount, $original);
                        $this->assertSame($amount, array_sum($allocation));
                        $remaining = [];
                        foreach ($original as $key => $value) {
                            $this->assertGreaterThanOrEqual(0, $allocation[$key]);
                            $this->assertLessThanOrEqual($value, $allocation[$key]);
                            $remaining[$key] = $value - $allocation[$key];
                        }
                        $final = Money::allocate($total - $amount, $remaining);
                        $this->assertSame($remaining, $final);
                    }
                }
            }
        }
    }

    public function test_money_math_uses_half_up_rounding_and_rejects_overflow(): void
    {
        $this->assertSame(1, Money::multiplyDivide(5, 100, 1000));
        $this->assertSame(0, Money::multiplyDivide(4, 100, 1000));
        $this->expectException(\InvalidArgumentException::class);
        Money::multiplyDivide(PHP_INT_MAX, 2, 1000);
    }

    public function test_invalid_components_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::allocate(1, ['tax' => 1.5]);
    }
}
