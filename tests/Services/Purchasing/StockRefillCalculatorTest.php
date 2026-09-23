<?php

declare(strict_types=1);

namespace App\Tests\Services\Purchasing;

use App\Services\Purchasing\StockRefillCalculator;
use PHPUnit\Framework\TestCase;

final class StockRefillCalculatorTest extends TestCase
{
    public function testWorkedExampleOrdersNine(): void
    {
        $calculator = new StockRefillCalculator();

        $this->assertSame(15, $calculator->targetStock(10));
        $this->assertSame(9, $calculator->orderQuantity(6, 10));
    }

    public function testExactMultipleOfFiveAfterBufferStaysPut(): void
    {
        $calculator = new StockRefillCalculator();

        $this->assertSame(55, $calculator->targetStock(50));
    }

    public function testNoOrderWhenStockAlreadyCoversTheTarget(): void
    {
        $calculator = new StockRefillCalculator();

        $this->assertSame(0, $calculator->orderQuantity(30, 20));
    }

    public function testZeroMinimumDoesNotOrder(): void
    {
        $calculator = new StockRefillCalculator();

        $this->assertSame(0, $calculator->targetStock(0));
        $this->assertSame(0, $calculator->orderQuantity(0, 0));
    }
}
