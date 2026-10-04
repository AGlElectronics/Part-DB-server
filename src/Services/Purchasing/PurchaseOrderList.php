<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Entity\Purchasing\PurchaseOrder;

/**
 * Splits orders into the current list (open, then partial) and history (received).
 * Not-yet-ordered rows stay above rows that already have an ordered-on date.
 */
final class PurchaseOrderList
{
    /**
     * @param array<int, PurchaseOrder> $orders
     *
     * @return array{open: list<PurchaseOrder>, partial: list<PurchaseOrder>, received: list<PurchaseOrder>}
     */
    public function group(array $orders, string $kind): array
    {
        $open = [];
        $partial = [];
        $received = [];
        foreach ($orders as $order) {
            if ($kind !== 'all' && $order->getKind() !== $kind) {
                continue;
            }
            match ($order->getFulfillment()) {
                'partial' => $partial[] = $order,
                'received' => $received[] = $order,
                default => $open[] = $order,
            };
        }

        return [
            'open' => $this->sort($open),
            'partial' => $this->sort($partial),
            'received' => $this->sort($received),
        ];
    }

    /**
     * @param list<PurchaseOrder> $orders
     *
     * @return list<PurchaseOrder>
     */
    private function sort(array $orders): array
    {
        usort($orders, static function (PurchaseOrder $left, PurchaseOrder $right): int {
            $leftDate = $left->getOrderedAt();
            $rightDate = $right->getOrderedAt();
            if ($leftDate === null && $rightDate !== null) {
                return -1;
            }
            if ($leftDate !== null && $rightDate === null) {
                return 1;
            }
            if ($leftDate === null && $rightDate === null) {
                return ($right->getId() ?? 0) <=> ($left->getId() ?? 0);
            }
            $compared = $rightDate <=> $leftDate;
            if ($compared !== 0) {
                return $compared;
            }

            return ($right->getId() ?? 0) <=> ($left->getId() ?? 0);
        });

        return $orders;
    }
}
