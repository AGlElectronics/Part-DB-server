<?php

declare(strict_types=1);

namespace App\Entity\Purchasing;

use App\Entity\Parts\Part;
use App\Entity\Parts\Supplier;
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
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Part $part = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $externalName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $supplierPartNumber = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Supplier $supplier = null;

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

    public function getPart(): ?Part
    {
        return $this->part;
    }

    public function isInDatabase(): bool
    {
        return $this->part instanceof Part;
    }

    public function getLabel(): string
    {
        if ($this->part instanceof Part) {
            return $this->part->getName();
        }

        return $this->externalName ?? '';
    }

    public function setPart(Part $part): void
    {
        $this->part = $part;
    }

    public function getExternalName(): ?string
    {
        return $this->externalName;
    }

    public function setExternalName(?string $externalName): void
    {
        $name = $externalName === null ? '' : trim($externalName);
        $this->externalName = $name === '' ? null : mb_substr($name, 0, 255);
    }

    public function getSupplierPartNumber(): ?string
    {
        return $this->supplierPartNumber;
    }

    public function setSupplierPartNumber(?string $supplierPartNumber): void
    {
        $number = $supplierPartNumber === null ? '' : trim($supplierPartNumber);
        $this->supplierPartNumber = $number === '' ? null : mb_substr($number, 0, 255);
    }

    public function getSupplier(): ?Supplier
    {
        return $this->supplier;
    }

    public function setSupplier(?Supplier $supplier): void
    {
        $this->supplier = $supplier;
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
