<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260821020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Preserve exact fractional quantities on canonical order intents';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE order_intent ALTER COLUMN size TYPE NUMERIC(36, 18) USING size::numeric');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM order_intent WHERE size <> trunc(size)) THEN
        RAISE EXCEPTION 'Cannot restore integer order_intent.size while fractional canonical quantities exist';
    END IF;
END
$$
SQL);
        $this->addSql('ALTER TABLE order_intent ALTER COLUMN size TYPE INTEGER USING size::integer');
    }
}
