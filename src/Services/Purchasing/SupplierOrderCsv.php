<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Entity\Purchasing\PurchaseOrder;

/**
 * Basket files the DigiKey and Mouser BOM importers can map by column name.
 */
final class SupplierOrderCsv
{
    public function __construct(private readonly SupplierPartNumberResolver $partNumbers)
    {
    }

    public function digikey(PurchaseOrder $order): string
    {
        $rows = [['Digi-Key Part Number', 'Quantity', 'Customer Reference']];
        foreach ($order->getLines() as $line) {
            if ($line->getQuantity() < 1) {
                continue;
            }
            $number = $this->partNumbers->digikey($line->getPart());
            if ($number === null) {
                continue;
            }
            $rows[] = [$number, (string) $line->getQuantity(), $line->getPart()->getName()];
        }

        return $this->render($rows);
    }

    public function mouser(PurchaseOrder $order): string
    {
        $rows = [['Mouser Part Number', 'Quantity', 'Customer #']];
        foreach ($order->getLines() as $line) {
            if ($line->getQuantity() < 1) {
                continue;
            }
            $number = $this->partNumbers->mouser($line->getPart());
            if ($number === null) {
                continue;
            }
            $rows[] = [$number, (string) $line->getQuantity(), mb_substr($line->getPart()->getName(), 0, 21)];
        }

        return $this->render($rows);
    }

    /**
     * @param list<list<string>> $rows
     */
    private function render(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Could not build the order file.');
        }
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
    }
}
