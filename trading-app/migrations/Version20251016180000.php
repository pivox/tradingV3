<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251016180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Restore klines.id auto-increment sequence default.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE SEQUENCE IF NOT EXISTS klines_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1");
        $this->addSql("ALTER SEQUENCE klines_id_seq OWNED BY klines.id");
        $this->addSql("SELECT setval('klines_id_seq', COALESCE((SELECT MAX(id) FROM klines), 0))");
        $this->addSql("ALTER TABLE klines ALTER COLUMN id SET DEFAULT nextval('klines_id_seq')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE klines ALTER COLUMN id DROP DEFAULT");
    }
}
