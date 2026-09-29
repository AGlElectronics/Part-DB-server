<?php

declare(strict_types=1);

namespace App\Entity\Purchasing;

use App\Entity\Parts\PartLot;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'purchase_order_receipts')]
class PurchaseOrderReceipt
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'receipts')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?PurchaseOrder $purchaseOrder = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $receivedAt;

    #[ORM\Column(type: Types::TEXT)]
    private string $comment = '';

    /** @var Collection<int, PurchaseOrderReceiptLine> */
    #[ORM\OneToMany(mappedBy: 'receipt', targetEntity: PurchaseOrderReceiptLine::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $lines;

    public function __construct()
    {
        $this->receivedAt = new \DateTimeImmutable();
        $this->lines = new ArrayCollection();
    }

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

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function getComment(): string
    {
        return $this->comment;
    }

    public function setComment(string $comment): void
    {
        $this->comment = $comment;
    }

    /**
     * @return Collection<int, PurchaseOrderReceiptLine>
     */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function addLine(PurchaseOrderLine $orderLine, PartLot $lot, int $quantity): PurchaseOrderReceiptLine
    {
        $line = new PurchaseOrderReceiptLine();
        $line->setReceipt($this);
        $line->setOrderLine($orderLine);
        $line->setPartLot($lot);
        $line->setQuantity($quantity);
        $this->lines->add($line);

        return $line;
    }
}
