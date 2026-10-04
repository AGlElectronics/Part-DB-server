<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\AbstractMultiPlatformMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20261002190000 extends AbstractMultiPlatformMigration
{
    public function getDescription(): string
    {
        return 'Give purchase orders a kind and a PO number, and allow order lines for parts that are not in the database yet';
    }

    public function mySQLUp(Schema $schema): void
    {
        $this->addSql("ALTER TABLE purchase_orders ADD kind VARCHAR(8) DEFAULT 'elec' NOT NULL, ADD number VARCHAR(32) DEFAULT NULL");
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PURCHASE_ORDERS_NUMBER ON purchase_orders (number)');
        $this->addSql('ALTER TABLE purchase_order_lines DROP FOREIGN KEY FK_PURCHASE_ORDER_LINES_PART');
        $this->addSql('ALTER TABLE purchase_order_lines CHANGE part_id part_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE purchase_order_lines ADD CONSTRAINT FK_PURCHASE_ORDER_LINES_PART FOREIGN KEY (part_id) REFERENCES parts (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE purchase_order_lines ADD external_name VARCHAR(255) DEFAULT NULL, ADD supplier_part_number VARCHAR(255) DEFAULT NULL, ADD supplier_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE purchase_order_lines ADD CONSTRAINT FK_PO_LINES_SUPPLIER FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_PO_LINES_SUPPLIER ON purchase_order_lines (supplier_id)');
    }

    public function mySQLDown(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_order_lines DROP FOREIGN KEY FK_PO_LINES_SUPPLIER');
        $this->addSql('DROP INDEX IDX_PO_LINES_SUPPLIER ON purchase_order_lines');
        $this->addSql('ALTER TABLE purchase_order_lines DROP external_name, DROP supplier_part_number, DROP supplier_id');
        $this->addSql('DELETE FROM purchase_order_lines WHERE part_id IS NULL');
        $this->addSql('ALTER TABLE purchase_order_lines DROP FOREIGN KEY FK_PURCHASE_ORDER_LINES_PART');
        $this->addSql('ALTER TABLE purchase_order_lines CHANGE part_id part_id INT NOT NULL');
        $this->addSql('ALTER TABLE purchase_order_lines ADD CONSTRAINT FK_PURCHASE_ORDER_LINES_PART FOREIGN KEY (part_id) REFERENCES parts (id) ON DELETE CASCADE');
        $this->addSql('DROP INDEX UNIQ_PURCHASE_ORDERS_NUMBER ON purchase_orders');
        $this->addSql('ALTER TABLE purchase_orders DROP number, DROP kind');
    }

    public function sqLiteUp(Schema $schema): void
    {
        $this->addSql("ALTER TABLE purchase_orders ADD COLUMN kind VARCHAR(8) NOT NULL DEFAULT 'elec'");
        $this->addSql('ALTER TABLE purchase_orders ADD COLUMN number VARCHAR(32) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PURCHASE_ORDERS_NUMBER ON purchase_orders (number)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__po_receipt_lines AS SELECT id, receipt_id, order_line_id, part_lot_id, quantity FROM purchase_order_receipt_lines');
        $this->addSql('DROP TABLE purchase_order_receipt_lines');
        $this->addSql('CREATE TEMPORARY TABLE __temp__po_lines AS SELECT id, purchase_order_id, part_id, target_stock, quantity, quantity_received FROM purchase_order_lines');
        $this->addSql('DROP TABLE purchase_order_lines');
        $this->addSql('CREATE TABLE purchase_order_lines (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, purchase_order_id INTEGER NOT NULL, part_id INTEGER DEFAULT NULL, target_stock INTEGER NOT NULL, quantity INTEGER NOT NULL, quantity_received INTEGER NOT NULL DEFAULT 0, external_name VARCHAR(255) DEFAULT NULL, supplier_part_number VARCHAR(255) DEFAULT NULL, supplier_id INTEGER DEFAULT NULL, CONSTRAINT FK_PURCHASE_ORDER_LINES_ORDER FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_PURCHASE_ORDER_LINES_PART FOREIGN KEY (part_id) REFERENCES parts (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_PO_LINES_SUPPLIER FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO purchase_order_lines (id, purchase_order_id, part_id, target_stock, quantity, quantity_received) SELECT id, purchase_order_id, part_id, target_stock, quantity, quantity_received FROM __temp__po_lines');
        $this->addSql('DROP TABLE __temp__po_lines');
        $this->addSql('CREATE INDEX IDX_PURCHASE_ORDER_LINES_ORDER ON purchase_order_lines (purchase_order_id)');
        $this->addSql('CREATE INDEX IDX_PURCHASE_ORDER_LINES_PART ON purchase_order_lines (part_id)');
        $this->addSql('CREATE INDEX IDX_PO_LINES_SUPPLIER ON purchase_order_lines (supplier_id)');
        $this->addSql('CREATE TABLE purchase_order_receipt_lines (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, receipt_id INTEGER NOT NULL, order_line_id INTEGER NOT NULL, part_lot_id INTEGER DEFAULT NULL, quantity INTEGER NOT NULL, CONSTRAINT FK_PO_RECEIPT_LINES_RECEIPT FOREIGN KEY (receipt_id) REFERENCES purchase_order_receipts (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_PO_RECEIPT_LINES_LINE FOREIGN KEY (order_line_id) REFERENCES purchase_order_lines (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_PO_RECEIPT_LINES_LOT FOREIGN KEY (part_lot_id) REFERENCES part_lots (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO purchase_order_receipt_lines (id, receipt_id, order_line_id, part_lot_id, quantity) SELECT id, receipt_id, order_line_id, part_lot_id, quantity FROM __temp__po_receipt_lines');
        $this->addSql('DROP TABLE __temp__po_receipt_lines');
        $this->addSql('CREATE INDEX IDX_PO_RECEIPT_LINES_RECEIPT ON purchase_order_receipt_lines (receipt_id)');
        $this->addSql('CREATE INDEX IDX_PO_RECEIPT_LINES_LINE ON purchase_order_receipt_lines (order_line_id)');
        $this->addSql('CREATE INDEX IDX_PO_RECEIPT_LINES_LOT ON purchase_order_receipt_lines (part_lot_id)');
    }

    public function sqLiteDown(Schema $schema): void
    {
        $this->addSql('DELETE FROM purchase_order_lines WHERE part_id IS NULL');
        $this->addSql('CREATE TEMPORARY TABLE __temp__po_receipt_lines AS SELECT id, receipt_id, order_line_id, part_lot_id, quantity FROM purchase_order_receipt_lines');
        $this->addSql('DROP TABLE purchase_order_receipt_lines');
        $this->addSql('CREATE TEMPORARY TABLE __temp__po_lines AS SELECT id, purchase_order_id, part_id, target_stock, quantity, quantity_received FROM purchase_order_lines');
        $this->addSql('DROP TABLE purchase_order_lines');
        $this->addSql('CREATE TABLE purchase_order_lines (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, purchase_order_id INTEGER NOT NULL, part_id INTEGER NOT NULL, target_stock INTEGER NOT NULL, quantity INTEGER NOT NULL, quantity_received INTEGER NOT NULL DEFAULT 0, CONSTRAINT FK_PURCHASE_ORDER_LINES_ORDER FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_PURCHASE_ORDER_LINES_PART FOREIGN KEY (part_id) REFERENCES parts (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO purchase_order_lines (id, purchase_order_id, part_id, target_stock, quantity, quantity_received) SELECT id, purchase_order_id, part_id, target_stock, quantity, quantity_received FROM __temp__po_lines');
        $this->addSql('DROP TABLE __temp__po_lines');
        $this->addSql('CREATE INDEX IDX_PURCHASE_ORDER_LINES_ORDER ON purchase_order_lines (purchase_order_id)');
        $this->addSql('CREATE INDEX IDX_PURCHASE_ORDER_LINES_PART ON purchase_order_lines (part_id)');
        $this->addSql('CREATE TABLE purchase_order_receipt_lines (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, receipt_id INTEGER NOT NULL, order_line_id INTEGER NOT NULL, part_lot_id INTEGER DEFAULT NULL, quantity INTEGER NOT NULL, CONSTRAINT FK_PO_RECEIPT_LINES_RECEIPT FOREIGN KEY (receipt_id) REFERENCES purchase_order_receipts (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_PO_RECEIPT_LINES_LINE FOREIGN KEY (order_line_id) REFERENCES purchase_order_lines (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_PO_RECEIPT_LINES_LOT FOREIGN KEY (part_lot_id) REFERENCES part_lots (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO purchase_order_receipt_lines (id, receipt_id, order_line_id, part_lot_id, quantity) SELECT id, receipt_id, order_line_id, part_lot_id, quantity FROM __temp__po_receipt_lines');
        $this->addSql('DROP TABLE __temp__po_receipt_lines');
        $this->addSql('CREATE INDEX IDX_PO_RECEIPT_LINES_RECEIPT ON purchase_order_receipt_lines (receipt_id)');
        $this->addSql('CREATE INDEX IDX_PO_RECEIPT_LINES_LINE ON purchase_order_receipt_lines (order_line_id)');
        $this->addSql('CREATE INDEX IDX_PO_RECEIPT_LINES_LOT ON purchase_order_receipt_lines (part_lot_id)');
        $this->addSql('DROP INDEX UNIQ_PURCHASE_ORDERS_NUMBER');
        $this->addSql('ALTER TABLE purchase_orders DROP COLUMN number');
        $this->addSql('ALTER TABLE purchase_orders DROP COLUMN kind');
    }

    public function postgreSQLUp(Schema $schema): void
    {
        $this->addSql("ALTER TABLE purchase_orders ADD kind VARCHAR(8) DEFAULT 'elec' NOT NULL");
        $this->addSql('ALTER TABLE purchase_orders ADD number VARCHAR(32) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PURCHASE_ORDERS_NUMBER ON purchase_orders (number)');
        $this->addSql('ALTER TABLE purchase_order_lines ALTER part_id DROP NOT NULL');
        $this->addSql('ALTER TABLE purchase_order_lines ADD external_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE purchase_order_lines ADD supplier_part_number VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE purchase_order_lines ADD supplier_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE purchase_order_lines ADD CONSTRAINT FK_PO_LINES_SUPPLIER FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_PO_LINES_SUPPLIER ON purchase_order_lines (supplier_id)');
    }

    public function postgreSQLDown(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_order_lines DROP CONSTRAINT FK_PO_LINES_SUPPLIER');
        $this->addSql('DROP INDEX IDX_PO_LINES_SUPPLIER');
        $this->addSql('DELETE FROM purchase_order_lines WHERE part_id IS NULL');
        $this->addSql('ALTER TABLE purchase_order_lines DROP external_name');
        $this->addSql('ALTER TABLE purchase_order_lines DROP supplier_part_number');
        $this->addSql('ALTER TABLE purchase_order_lines DROP supplier_id');
        $this->addSql('ALTER TABLE purchase_order_lines ALTER part_id SET NOT NULL');
        $this->addSql('DROP INDEX UNIQ_PURCHASE_ORDERS_NUMBER');
        $this->addSql('ALTER TABLE purchase_orders DROP number');
        $this->addSql('ALTER TABLE purchase_orders DROP kind');
    }

    public function postUp(Schema $schema): void
    {
        parent::postUp($schema);
        $this->assignExistingNumbers();
    }

    private function assignExistingNumbers(): void
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative('SELECT id, created_at FROM purchase_orders WHERE number IS NULL ORDER BY created_at ASC, id ASC');
        $sequence = [];
        foreach ($rows as $row) {
            $created = (string) $row['created_at'];
            $year = preg_match('/^(\d{4})/', $created, $matches) === 1 ? $matches[1] : '2026';
            $sequence[$year] = ($sequence[$year] ?? 0) + 1;
            $number = 'Elec-'.$year.str_pad((string) $sequence[$year], 4, '0', STR_PAD_LEFT);
            $this->connection->update('purchase_orders', ['number' => $number], ['id' => $row['id']]);
        }
    }
}
