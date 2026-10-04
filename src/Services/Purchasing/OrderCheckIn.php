<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Entity\Parts\Part;
use App\Entity\Parts\PartLot;
use App\Entity\Parts\StorageLocation;
use App\Entity\Purchasing\PurchaseOrder;
use App\Entity\Purchasing\PurchaseOrderLine;
use App\Entity\Purchasing\PurchaseOrderReceipt;
use App\Repository\Parts\StorelocationRepository;
use App\Services\Parts\PartLotWithdrawAddHelper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

final class OrderCheckIn
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PartLotWithdrawAddHelper $stock,
        private readonly Security $security,
    ) {
    }

    /**
     * @return list<PartLot>
     */
    public function usableLots(Part $part): array
    {
        $lots = [];
        foreach ($part->getPartLots() as $lot) {
            if ($this->stock->canAdd($lot)) {
                $lots[] = $lot;
            }
        }

        return $lots;
    }

    /**
     * @return list<StorageLocation>
     */
    public function locationChoices(Part $part): array
    {
        /** @var list<StorageLocation> $locations */
        $locations = $this->entityManager->getRepository(StorageLocation::class)->findBy([], ['name' => 'ASC']);
        $choices = [];
        foreach ($locations as $location) {
            if ($this->locationAcceptsNewLot($part, $location)) {
                $choices[] = $location;
            }
        }

        return $choices;
    }

    public function describeLot(PartLot $lot): string
    {
        $place = $lot->getStorageLocation()?->getName() ?? '';
        $name = trim($lot->getDescription());
        $amount = (string) $lot->getAmount();
        if ($name !== '' && $place !== '') {
            return $name.', '.$place.' ('.$amount.')';
        }
        if ($place !== '') {
            return $place.' ('.$amount.')';
        }
        if ($name !== '') {
            return $name.' ('.$amount.')';
        }

        return $amount;
    }

    /**
     * @param array<int, array{quantity: int, lotId: int, locationId: int}> $posted
     *
     * @return list<OrderCheckInLine>
     */
    public function resolve(PurchaseOrder $order, array $posted): array
    {
        $resolved = [];
        foreach ($posted as $lineId => $row) {
            $line = $this->line($order, $lineId);
            $part = $this->part($line);
            if ($row['quantity'] <= 0) {
                throw new OrderCheckInException('purchase_order.check_in.invalid_quantity', ['%part%' => $part->getName()]);
            }
            $resolved[] = $this->destination($line, $row['quantity'], $row['lotId'], $row['locationId']);
        }

        return $resolved;
    }

    /**
     * @param list<OrderCheckInLine> $rows
     */
    public function apply(PurchaseOrder $order, array $rows, string $comment): PurchaseOrderReceipt
    {
        $receipt = $this->entityManager->wrapInTransaction(function () use ($order, $rows, $comment): PurchaseOrderReceipt {
            $receipt = new PurchaseOrderReceipt();
            $receipt->setComment($comment);
            $order->addReceipt($receipt);
            $this->entityManager->persist($receipt);

            foreach ($rows as $row) {
                $part = $this->part($row->line);
                $lot = $this->prepareLot($row, $part);
                $this->stock->add($lot, (float) $row->quantity, $comment);
                $row->line->addQuantityReceived($row->quantity);
                $receipt->addLine($row->line, $lot, $row->quantity);
            }

            return $receipt;
        });
        if (!$receipt instanceof PurchaseOrderReceipt) {
            throw new OrderCheckInException('purchase_order.check_in.lot_rejected');
        }

        return $receipt;
    }

    private function prepareLot(OrderCheckInLine $row, Part $part): PartLot
    {
        $lot = $row->lot;
        if ($row->newLot) {
            if (!$this->security->isGranted('create', $lot)) {
                throw new OrderCheckInException('purchase_order.check_in.denied', ['%part%' => $part->getName()]);
            }
            $part->addPartLot($lot);
            $this->entityManager->persist($lot);
            $this->entityManager->flush();
        }
        if (!$this->security->isGranted('add', $lot)) {
            throw new OrderCheckInException('purchase_order.check_in.denied', ['%part%' => $part->getName()]);
        }
        if (!$this->stock->canAdd($lot)) {
            throw new OrderCheckInException('purchase_order.check_in.lot_rejected', ['%part%' => $part->getName()]);
        }

        return $lot;
    }

    private function destination(PurchaseOrderLine $line, int $quantity, int $lotId, int $locationId): OrderCheckInLine
    {
        $part = $this->part($line);
        $usable = $this->usableLots($part);
        if ($lotId > 0) {
            foreach ($usable as $lot) {
                if ($lot->getID() === $lotId) {
                    return new OrderCheckInLine($line, $quantity, $lot, false, $lot->getStorageLocation());
                }
            }
            throw new OrderCheckInException('purchase_order.check_in.lot_rejected', ['%part%' => $part->getName()]);
        }
        if (count($usable) === 1) {
            return new OrderCheckInLine($line, $quantity, $usable[0], false, $usable[0]->getStorageLocation());
        }
        if (count($usable) > 1) {
            throw new OrderCheckInException('purchase_order.check_in.choose_lot', ['%part%' => $part->getName()]);
        }

        return $this->newLot($line, $quantity, $locationId);
    }

    private function newLot(PurchaseOrderLine $line, int $quantity, int $locationId): OrderCheckInLine
    {
        $part = $this->part($line);
        $choices = $this->locationChoices($part);
        if ($choices === []) {
            throw new OrderCheckInException('purchase_order.check_in.no_location', ['%part%' => $part->getName()]);
        }
        $location = null;
        foreach ($choices as $choice) {
            if ($choice->getID() === $locationId) {
                $location = $choice;
                break;
            }
        }
        if (!$location instanceof StorageLocation) {
            throw new OrderCheckInException('purchase_order.check_in.choose_location', ['%part%' => $part->getName()]);
        }

        $lot = new PartLot();
        $lot->setPart($part);
        $lot->setStorageLocation($location);

        return new OrderCheckInLine($line, $quantity, $lot, true, $location);
    }

    private function part(PurchaseOrderLine $line): Part
    {
        $part = $line->getPart();
        if (!$part instanceof Part) {
            throw new OrderCheckInException('purchase_order.check_in.not_in_database', ['%part%' => $line->getLabel()]);
        }

        return $part;
    }

    private function line(PurchaseOrder $order, int $id): PurchaseOrderLine
    {
        foreach ($order->getLines() as $line) {
            if ($line->getId() === $id) {
                return $line;
            }
        }

        throw new OrderCheckInException('purchase_order.check_in.invalid_line');
    }

    private function locationAcceptsNewLot(Part $part, StorageLocation $location): bool
    {
        if ($location->isFull()) {
            return false;
        }

        $repository = $this->entityManager->getRepository(StorageLocation::class);
        if (!$repository instanceof StorelocationRepository) {
            return true;
        }
        $parts = $repository->getParts($location);
        $contains = false;
        foreach ($parts as $existing) {
            if ($existing instanceof Part && $existing->getID() === $part->getID()) {
                $contains = true;
                break;
            }
        }
        if ($location->isOnlySinglePart() && $parts !== [] && !$contains) {
            return false;
        }
        if ($location->isLimitToExistingParts() && !$contains) {
            return false;
        }

        return true;
    }
}
