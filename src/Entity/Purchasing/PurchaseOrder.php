<?php

declare(strict_types=1);

namespace App\Entity\Purchasing;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'purchase_orders')]
class PurchaseOrder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, PurchaseOrderLine> */
    #[ORM\OneToMany(mappedBy: 'purchaseOrder', targetEntity: PurchaseOrderLine::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $lines;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->name = 'Order '.$this->createdAt->format('Y-m-d H:i');
        $this->lines = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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
}
