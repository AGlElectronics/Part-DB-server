<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\AbstractMultiPlatformMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260929140000 extends AbstractMultiPlatformMigration
{
    public function getDescription(): string
    {
        return 'Record when a purchase order was placed, and store check-ins that add received parts to stock';
    }

    public function mySQLUp(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_orders ADD ordered_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE purchase_order_lines ADD quantity_received INT NOT NULL DEFAULT 0');
        $this->addSql('CREATE TABLE purchase_order_receipts (id INT AUTO_INCREMENT NOT NULL, purchase_order_id INT NOT NULL, received_at DATETIME NOT NULL, comment LONGTEXT NOT NULL, INDEX IDX_PO_RECEIPTS_ORDER (purchase_order_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE purchase_order_receipt_lines (id INT AUTO_INCREMENT NOT NULL, receipt_id INT NOT NULL, order_line_id INT NOT NULL, part_lot_id INT DEFAULT NULL, quantity INT NOT NULL, INDEX IDX_PO_RECEIPT_LINES_RECEIPT (receipt_id), INDEX IDX_PO_RECEIPT_LINES_LINE (order_line_id), INDEX IDX_PO_RECEIPT_LINES_LOT (part_lot_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE purchase_order_receipts ADD CONSTRAINT FK_PO_RECEIPTS_ORDER FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE purchase_order_receipt_lines ADD CONSTRAINT FK_PO_RECEIPT_LINES_RECEIPT FOREIGN KEY (receipt_id) REFERENCES purchase_order_receipts (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE purchase_order_receipt_lines ADD CONSTRAINT FK_PO_RECEIPT_LINES_LINE FOREIGN KEY (order_line_id) REFERENCES purchase_order_lines (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE purchase_order_receipt_lines ADD CONSTRAINT FK_PO_RECEIPT_LINES_LOT FOREIGN KEY (part_lot_id) REFERENCES part_lots (id) ON DELETE SET NULL');
    }

    public function mySQLDown(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_order_receipt_lines DROP FOREIGN KEY FK_PO_RECEIPT_LINES_LOT');
        $this->addSql('ALTER TABLE purchase_order_receipt_lines DROP FOREIGN KEY FK_PO_RECEIPT_LINES_LINE');
        $this->addSql('ALTER TABLE purchase_order_receipt_lines DROP FOREIGN KEY FK_PO_RECEIPT_LINES_RECEIPT');
        $this->addSql('ALTER TABLE purchase_order_receipts DROP FOREIGN KEY FK_PO_RECEIPTS_ORDER');
        $this->addSql('DROP TABLE purchase_order_receipt_lines');
        $this->addSql('DROP TABLE purchase_order_receipts');
        $this->addSql('ALTER TABLE purchase_order_lines DROP quantity_received');
        $this->addSql('ALTER TABLE purchase_orders DROP ordered_at');
    }

    public function sqLiteUp(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_orders ADD COLUMN ordered_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE purchase_order_lines ADD COLUMN quantity_received INTEGER NOT NULL DEFAULT 0');
        $this->addSql('CREATE TABLE purchase_order_receipts (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, purchase_order_id INTEGER NOT NULL, received_at DATETIME NOT NULL, comment CLOB NOT NULL, CONSTRAINT FK_PO_RECEIPTS_ORDER FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_PO_RECEIPTS_ORDER ON purchase_order_receipts (purchase_order_id)');
        $this->addSql('CREATE TABLE purchase_order_receipt_lines (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, receipt_id INTEGER NOT NULL, order_line_id INTEGER NOT NULL, part_lot_id INTEGER DEFAULT NULL, quantity INTEGER NOT NULL, CONSTRAINT FK_PO_RECEIPT_LINES_RECEIPT FOREIGN KEY (receipt_id) REFERENCES purchase_order_receipts (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_PO_RECEIPT_LINES_LINE FOREIGN KEY (order_line_id) REFERENCES purchase_order_lines (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_PO_RECEIPT_LINES_LOT FOREIGN KEY (part_lot_id) REFERENCES part_lots (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_PO_RECEIPT_LINES_RECEIPT ON purchase_order_receipt_lines (receipt_id)');
        $this->addSql('CREATE INDEX IDX_PO_RECEIPT_LINES_LINE ON purchase_order_receipt_lines (order_line_id)');
        $this->addSql('CREATE INDEX IDX_PO_RECEIPT_LINES_LOT ON purchase_order_receipt_lines (part_lot_id)');
    }

    public function sqLiteDown(Schema $schema): void
    {
        $this->addSql('DROP TABLE purchase_order_receipt_lines');
        $this->addSql('DROP TABLE purchase_order_receipts');
        $this->addSql('ALTER TABLE purchase_order_lines DROP COLUMN quantity_received');
        $this->addSql('ALTER TABLE purchase_orders DROP COLUMN ordered_at');
    }

    public function postgreSQLUp(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_orders ADD ordered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE purchase_order_lines ADD quantity_received INT DEFAULT 0 NOT NULL');
        $this->addSql('CREATE TABLE purchase_order_receipts (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, purchase_order_id INT NOT NULL, received_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, comment TEXT NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_PO_RECEIPTS_ORDER ON purchase_order_receipts (purchase_order_id)');
        $this->addSql('CREATE TABLE purchase_order_receipt_lines (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, receipt_id INT NOT NULL, order_line_id INT NOT NULL, part_lot_id INT DEFAULT NULL, quantity INT NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_PO_RECEIPT_LINES_RECEIPT ON purchase_order_receipt_lines (receipt_id)');
        $this->addSql('CREATE INDEX IDX_PO_RECEIPT_LINES_LINE ON purchase_order_receipt_lines (order_line_id)');
        $this->addSql('CREATE INDEX IDX_PO_RECEIPT_LINES_LOT ON purchase_order_receipt_lines (part_lot_id)');
        $this->addSql('ALTER TABLE purchase_order_receipts ADD CONSTRAINT FK_PO_RECEIPTS_ORDER FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE purchase_order_receipt_lines ADD CONSTRAINT FK_PO_RECEIPT_LINES_RECEIPT FOREIGN KEY (receipt_id) REFERENCES purchase_order_receipts (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE purchase_order_receipt_lines ADD CONSTRAINT FK_PO_RECEIPT_LINES_LINE FOREIGN KEY (order_line_id) REFERENCES purchase_order_lines (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE purchase_order_receipt_lines ADD CONSTRAINT FK_PO_RECEIPT_LINES_LOT FOREIGN KEY (part_lot_id) REFERENCES part_lots (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function postgreSQLDown(Schema $schema): void
    {
        $this->addSql('DROP TABLE purchase_order_receipt_lines');
        $this->addSql('DROP TABLE purchase_order_receipts');
        $this->addSql('ALTER TABLE purchase_order_lines DROP quantity_received');
        $this->addSql('ALTER TABLE purchase_orders DROP ordered_at');
    }
}
