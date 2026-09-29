<?php

declare(strict_types=1);

namespace App\Entity\Purchasing;

use App\Entity\Parts\Part;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'purchase_order_lines')]
class PurchaseOrderLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?PurchaseOrder $purchaseOrder = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Part $part = null;

    #[ORM\Column]
    private int $targetStock = 0;

    #[ORM\Column]
    private int $quantity = 0;

    #[ORM\Column]
    private int $quantityReceived = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPurchaseOrder(): ?PurchaseOrder
    {
        return $this->purchaseOrder;
    }

    public function setPurchaseOrder(PurchaseOrder $purchaseOrder): void
    {
        $this->purchaseOrder = $purchaseOrder;
    }

    public function getPart(): Part
    {
        if (!$this->part instanceof Part) {
            throw new \LogicException('A purchase order line must have a part.');
        }

        return $this->part;
    }

    public function setPart(Part $part): void
    {
        $this->part = $part;
    }

    public function getTargetStock(): int
    {
        return $this->targetStock;
    }

    public function setTargetStock(int $targetStock): void
    {
        $this->targetStock = max(0, $targetStock);
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): void
    {
        $this->quantity = max(0, $quantity);
    }

    public function getQuantityReceived(): int
    {
        return $this->quantityReceived;
    }

    public function addQuantityReceived(int $quantity): void
    {
        $this->quantityReceived += max(0, $quantity);
    }

    public function getOutstanding(): int
    {
        return max(0, $this->quantity - $this->quantityReceived);
    }
}
