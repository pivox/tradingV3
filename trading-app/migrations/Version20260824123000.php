<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260824123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep Paper journal recovery indexed by event type before PostgreSQL statistics exist.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE INDEX idx_paper_execution_event_typed_source ON paper_execution_event (cell_id, event_type, source_position) WHERE source_position IS NOT NULL");
        $this->addSql("CREATE INDEX idx_paper_execution_event_typed_effect ON paper_execution_event (cell_id, event_type, effect_key) WHERE effect_key IS NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_paper_execution_event_typed_effect');
        $this->addSql('DROP INDEX idx_paper_execution_event_typed_source');
    }
}
