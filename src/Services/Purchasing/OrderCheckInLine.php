<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Entity\Parts\PartLot;
use App\Entity\Parts\StorageLocation;
use App\Entity\Purchasing\PurchaseOrderLine;

final readonly class OrderCheckInLine
{
    public function __construct(
        public PurchaseOrderLine $line,
        public int $quantity,
        public PartLot $lot,
        public bool $newLot,
        public ?StorageLocation $location,
    ) {
    }
}
