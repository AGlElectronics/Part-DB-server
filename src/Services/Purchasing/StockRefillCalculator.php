<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

/**
 * Turns a minimum stock level into an order quantity.
 *
 * Target stock is the required amount plus 10%, rounded up to the next
 * multiple of 5. The order quantity is that target minus what is already
 * in stock. Six in stock with a minimum of 10 becomes a target of 15 and
 * an order of 9.
 */
final class StockRefillCalculator
{
    public function targetStock(float $required): int
    {
        if ($required <= 0) {
            return 0;
        }

        $buffered = round($required * 1.1, 6);

        return (int) (ceil(($buffered / 5) - 1e-9) * 5);
    }

    public function orderQuantity(float $inStock, float $required): int
    {
        $missing = $this->targetStock($required) - $inStock;
        if ($missing <= 0) {
            return 0;
        }

        return (int) ceil($missing - 1e-9);
    }
}
