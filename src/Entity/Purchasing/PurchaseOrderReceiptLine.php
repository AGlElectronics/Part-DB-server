<?php

declare(strict_types=1);

namespace App\Entity\Purchasing;

use App\Entity\Parts\PartLot;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'purchase_order_receipt_lines')]
class PurchaseOrderReceiptLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?PurchaseOrderReceipt $receipt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?PurchaseOrderLine $orderLine = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?PartLot $partLot = null;

    #[ORM\Column]
    private int $quantity = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReceipt(): ?PurchaseOrderReceipt
    {
        return $this->receipt;
    }

    public function setReceipt(PurchaseOrderReceipt $receipt): void
    {
        $this->receipt = $receipt;
    }

    public function getOrderLine(): ?PurchaseOrderLine
    {
        return $this->orderLine;
    }

    public function setOrderLine(PurchaseOrderLine $orderLine): void
    {
        $this->orderLine = $orderLine;
    }

    public function getPartLot(): ?PartLot
    {
        return $this->partLot;
    }

    public function setPartLot(?PartLot $partLot): void
    {
        $this->partLot = $partLot;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): void
    {
        $this->quantity = max(0, $quantity);
    }
}
