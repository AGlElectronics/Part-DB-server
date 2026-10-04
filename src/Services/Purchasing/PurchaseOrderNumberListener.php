<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Entity\Purchasing\PurchaseOrder;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

#[AsEntityListener(event: Events::prePersist, method: 'prePersist', entity: PurchaseOrder::class)]
final class PurchaseOrderNumberListener
{
    public function __construct(private readonly PurchaseOrderNumberGenerator $numbers)
    {
    }

    public function prePersist(PurchaseOrder $order): void
    {
        if ($order->getNumber() !== '') {
            return;
        }

        $order->setNumber($this->numbers->next($order->getKind(), $order->getCreatedAt()));
    }
}
