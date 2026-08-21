<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260821020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Preserve exact canonical quantities and prices on order intents';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE order_intent ALTER COLUMN size TYPE NUMERIC(36, 18) USING size::numeric');
        $this->addSql('ALTER TABLE order_intent ALTER COLUMN price TYPE NUMERIC(65, 30) USING price::numeric');
        $this->addSql('ALTER TABLE order_protection ALTER COLUMN price TYPE NUMERIC(65, 30) USING price::numeric');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM order_intent
        WHERE size <> trunc(size)
           OR (price IS NOT NULL AND (price <> round(price, 12) OR abs(price) >= 1000000000000))
    ) OR EXISTS (
        SELECT 1 FROM order_protection
        WHERE price <> round(price, 12) OR abs(price) >= 1000000000000
    ) THEN
        RAISE EXCEPTION 'Cannot restore legacy order decimal columns without losing canonical precision';
    END IF;
END
$$
SQL);
        $this->addSql('ALTER TABLE order_intent ALTER COLUMN size TYPE INTEGER USING size::integer');
        $this->addSql('ALTER TABLE order_intent ALTER COLUMN price TYPE NUMERIC(24, 12) USING price::numeric');
        $this->addSql('ALTER TABLE order_protection ALTER COLUMN price TYPE NUMERIC(24, 12) USING price::numeric');
    }
}
