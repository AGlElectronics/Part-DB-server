<?php

declare(strict_types=1);

namespace App\Entity\Purchasing;

use App\Entity\Parts\Part;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'purchase_orders')]
#[ORM\UniqueConstraint(name: 'UNIQ_PURCHASE_ORDERS_NUMBER', columns: ['number'])]
class PurchaseOrder
{
    public const KIND_ELEC = 'elec';

    public const KIND_MECH = 'mech';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $number = null;

    #[ORM\Column(length: 8, options: ['default' => self::KIND_ELEC])]
    private string $kind = self::KIND_ELEC;

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $orderedAt = null;

    /** @var Collection<int, PurchaseOrderLine> */
    #[ORM\OneToMany(mappedBy: 'purchaseOrder', targetEntity: PurchaseOrderLine::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $lines;

    /** @var Collection<int, PurchaseOrderReceipt> */
    #[ORM\OneToMany(mappedBy: 'purchaseOrder', targetEntity: PurchaseOrderReceipt::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['receivedAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $receipts;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->lines = new ArrayCollection();
        $this->receipts = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumber(): string
    {
        return $this->number ?? '';
    }

    public function setNumber(string $number): void
    {
        $this->number = $number;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): void
    {
        $this->kind = $kind === self::KIND_MECH ? self::KIND_MECH : self::KIND_ELEC;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getOrderedAt(): ?\DateTimeImmutable
    {
        return $this->orderedAt;
    }

    public function setOrderedAt(?\DateTimeImmutable $orderedAt): void
    {
        $this->orderedAt = $orderedAt;
    }

    /**
     * Open when nothing has been received, partial when some has, received when every ordered line is filled.
     */
    public function getFulfillment(): string
    {
        $receivedAny = false;
        $stillOpen = false;
        $trackable = false;
        foreach ($this->lines as $line) {
            if ($line->getQuantityReceived() > 0) {
                $receivedAny = true;
            }
            if ($line->getQuantity() <= 0) {
                continue;
            }
            $trackable = true;
            if ($line->getQuantityReceived() < $line->getQuantity()) {
                $stillOpen = true;
            }
        }
        if (!$receivedAny) {
            return 'open';
        }
        if ($trackable && $stillOpen) {
            return 'partial';
        }

        return 'received';
    }

    /**
     * @return Collection<int, PurchaseOrderLine>
     */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function addLine(PurchaseOrderLine $line): void
    {
        if ($this->lines->contains($line)) {
            return;
        }
        $this->lines->add($line);
        $line->setPurchaseOrder($this);
    }

    public function findLineForPart(Part $part): ?PurchaseOrderLine
    {
        foreach ($this->lines as $line) {
            $linked = $line->getPart();
            if ($linked instanceof Part && $linked->getID() === $part->getID()) {
                return $line;
            }
        }

        return null;
    }

    public function removeLine(PurchaseOrderLine $line): void
    {
        $this->lines->removeElement($line);
    }

    /**
     * @return Collection<int, PurchaseOrderReceipt>
     */
    public function getReceipts(): Collection
    {
        return $this->receipts;
    }

    public function addReceipt(PurchaseOrderReceipt $receipt): void
    {
        if ($this->receipts->contains($receipt)) {
            return;
        }
        $this->receipts->add($receipt);
        $receipt->setPurchaseOrder($this);
    }
}
