<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\AbstractMultiPlatformMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260929143000 extends AbstractMultiPlatformMigration
{
    public function getDescription(): string
    {
        return 'Store the purchase order placed date as a timestamp so it matches the application date type';
    }

    public function mySQLUp(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_orders MODIFY ordered_at DATETIME DEFAULT NULL');
    }

    public function mySQLDown(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_orders MODIFY ordered_at DATE DEFAULT NULL');
    }

    public function sqLiteUp(Schema $schema): void
    {
    }

    public function sqLiteDown(Schema $schema): void
    {
    }

    public function postgreSQLUp(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_orders ALTER COLUMN ordered_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE USING ordered_at::timestamp');
    }

    public function postgreSQLDown(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_orders ALTER COLUMN ordered_at TYPE DATE USING ordered_at::date');
    }
}
