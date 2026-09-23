<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Entity\Parts\Part;
use App\Entity\PriceInformations\Orderdetail;

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
