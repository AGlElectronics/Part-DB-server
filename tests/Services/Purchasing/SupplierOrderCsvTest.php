<?php

declare(strict_types=1);

namespace App\Tests\Services\Purchasing;

use App\Entity\Parts\Supplier;
use App\Entity\Purchasing\PurchaseOrder;
use App\Entity\Purchasing\PurchaseOrderLine;
use App\Services\Purchasing\SupplierOrderCsv;
use App\Services\Purchasing\SupplierPartNumberResolver;
use PHPUnit\Framework\TestCase;

final class SupplierOrderCsvTest extends TestCase
{
    public function testExternalElectricalPartIsIncludedInDigiKeyFile(): void
    {
        $supplier = new Supplier();
        $supplier->setName('DigiKey');
        $order = $this->externalOrder($supplier);

        $csv = $this->csv()->digikey($order);

        self::assertStringContainsString('"Digi-Key Part Number",Quantity,"Customer Reference"', $csv);
        self::assertStringContainsString('DK-123,12,"DIN 912 M5x20"', $csv);
    }

    public function testExternalMechanicalPartUsesSelectedSupplierFile(): void
    {
        $supplier = new Supplier();
        $supplier->setName('Fastener shop');
        $order = $this->externalOrder($supplier);

        $csv = $this->csv()->forSupplier($order, $supplier);

        self::assertStringContainsString('"Part Number",Quantity,"Customer Reference"', $csv);
        self::assertStringContainsString('DK-123,12,"DIN 912 M5x20"', $csv);
    }

    private function externalOrder(Supplier $supplier): PurchaseOrder
    {
        $line = new PurchaseOrderLine();
        $line->setExternalName('DIN 912 M5x20');
        $line->setSupplier($supplier);
        $line->setSupplierPartNumber('DK-123');
        $line->setQuantity(12);
        $order = new PurchaseOrder();
        $order->addLine($line);

        return $order;
    }

    private function csv(): SupplierOrderCsv
    {
        return new SupplierOrderCsv(new SupplierPartNumberResolver());
    }
}
