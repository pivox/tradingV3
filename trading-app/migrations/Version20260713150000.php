<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260713150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add authoritative exact order quantities while retaining legacy integer columns.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE futures_order ADD quantity_decimal NUMERIC(36, 18) DEFAULT NULL');
        $this->addSql('ALTER TABLE futures_order ADD filled_quantity_decimal NUMERIC(36, 18) DEFAULT NULL');
        $this->addSql('UPDATE futures_order SET quantity_decimal = CAST(size AS NUMERIC) WHERE quantity_decimal IS NULL AND size IS NOT NULL');
        $this->addSql('UPDATE futures_order SET filled_quantity_decimal = CAST(filled_size AS NUMERIC) WHERE filled_quantity_decimal IS NULL AND filled_size IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE futures_order DROP quantity_decimal');
        $this->addSql('ALTER TABLE futures_order DROP filled_quantity_decimal');
    }
}
