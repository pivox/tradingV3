<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260715150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add authoritative exact fill quantity while retaining the legacy integer size.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE futures_order_trade ADD quantity_decimal NUMERIC(36, 18) DEFAULT NULL');
        $this->addSql('UPDATE futures_order_trade SET quantity_decimal = CAST(size AS NUMERIC) WHERE quantity_decimal IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE futures_order_trade DROP quantity_decimal');
    }
}
