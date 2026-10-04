<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Entity\Parts\Part;
use App\Entity\Parts\Supplier;
use App\Entity\PriceInformations\Orderdetail;
use App\Entity\Purchasing\PurchaseOrderLine;

/**
 * Picks the supplier part number used in a DigiKey or Mouser basket file.
 * The first non-obsolete order detail whose supplier name matches wins.
 */
final class SupplierPartNumberResolver
{
    public function digikey(Part $part): ?string
    {
        return $this->find($part, ['digikey', 'digi-key', 'digi key']);
    }

    public function mouser(Part $part): ?string
    {
        return $this->find($part, ['mouser']);
    }

    public function digikeyLine(PurchaseOrderLine $line): ?string
    {
        return $this->lineNumber($line, ['digikey', 'digi-key', 'digi key']);
    }

    public function mouserLine(PurchaseOrderLine $line): ?string
    {
        return $this->lineNumber($line, ['mouser']);
    }

    public function supplierLine(PurchaseOrderLine $line, Supplier $supplier): ?string
    {
        $part = $line->getPart();
        if ($part instanceof Part) {
            $number = $this->forSupplier($part, $supplier);
            if ($number !== null) {
                return $number;
            }
        }

        $lineSupplier = $line->getSupplier();
        if (!$lineSupplier instanceof Supplier) {
            return null;
        }
        $sameSupplier = $lineSupplier === $supplier
            || ($lineSupplier->getID() !== null && $lineSupplier->getID() === $supplier->getID());
        if (!$sameSupplier) {
            return null;
        }
        $number = trim((string) $line->getSupplierPartNumber());

        return $number === '' ? null : $number;
    }

    public function forSupplier(Part $part, Supplier $supplier): ?string
    {
        $wantedId = $supplier->getID();
        $wanted = strtolower($supplier->getName());
        foreach ($part->getOrderdetails(true) as $detail) {
            if (!$detail instanceof Orderdetail) {
                continue;
            }
            $detailSupplier = $detail->getSupplier();
            if ($detailSupplier === null) {
                continue;
            }
            $sameId = $wantedId !== null && $detailSupplier->getID() === $wantedId;
            $name = strtolower($detailSupplier->getName());
            if (!$sameId && ($wanted === '' || !str_contains($name, $wanted))) {
                continue;
            }
            $number = trim($detail->getSupplierPartNr());
            if ($number !== '') {
                return $number;
            }
        }

        return null;
    }

    /**
     * @param list<string> $needles
     */
    private function lineNumber(PurchaseOrderLine $line, array $needles): ?string
    {
        $part = $line->getPart();
        if ($part instanceof Part) {
            $number = $this->find($part, $needles);
            if ($number !== null) {
                return $number;
            }
        }

        return $this->externalNumber($line, $needles);
    }

    /**
     * @param list<string> $needles
     */
    private function externalNumber(PurchaseOrderLine $line, array $needles): ?string
    {
        $supplier = $line->getSupplier();
        if (!$supplier instanceof Supplier) {
            return null;
        }
        $name = strtolower($supplier->getName());
        foreach ($needles as $needle) {
            if (!str_contains($name, $needle)) {
                continue;
            }
            $number = trim((string) $line->getSupplierPartNumber());

            return $number === '' ? null : $number;
        }

        return null;
    }

    /**
     * @param list<string> $needles
     */
    private function find(Part $part, array $needles): ?string
    {
        foreach ($part->getOrderdetails(true) as $detail) {
            if (!$detail instanceof Orderdetail) {
                continue;
            }
            $supplier = $detail->getSupplier();
            if ($supplier === null) {
                continue;
            }
            $name = strtolower($supplier->getName());
            foreach ($needles as $needle) {
                if (!str_contains($name, $needle)) {
                    continue;
                }
                $number = trim($detail->getSupplierPartNr());
                if ($number !== '') {
                    return $number;
                }
            }
        }

        return null;
    }
}
