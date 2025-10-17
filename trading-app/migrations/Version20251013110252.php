<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251013110252 extends AbstractMigration
{
    private function splitSqlStatements(string $sql): array
    {
        $stmts = [];
        $current = '';
        $len = \strlen($sql);
        $inSingle = false;
        $inDouble = false;
        $inDollar = false;

        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            $next2 = ($i + 1 < $len) ? $sql[$i + 1] : '';

            // Detect $$ toggling for dollar-quoted functions
            if (!$inSingle && !$inDouble && $ch === '$' && $next2 === '$') {
                $inDollar = !$inDollar;
                $current .= '$$';
                $i++; // skip next '$'
                continue;
            }

            if (!$inDollar) {
                // handle quotes outside dollar blocks
                if (!$inDouble && $ch === "'" && ($i === 0 || $sql[$i - 1] !== '\\')) {
                    $inSingle = !$inSingle;
                } elseif (!$inSingle && $ch === '"' && ($i === 0 || $sql[$i - 1] !== '\\')) {
                    $inDouble = !$inDouble;
                }
            }

            if ($ch === ';' && !$inSingle && !$inDouble && !$inDollar) {
                $trimmed = \trim($current);
                if ($trimmed !== '') {
                    $stmts[] = $trimmed;
                }
                $current = '';
            } else {
                $current .= $ch;
            }
        }

        $trimmed = \trim($current);
        if ($trimmed !== '') {
            $stmts[] = $trimmed;
        }

        return $stmts;
    }

    public function up(Schema $schema): void
    {
        // Résoudre les chemins des fichiers SQL (supporte deux emplacements)
        $projectRoot = \dirname(__DIR__); // ../trading-app

        $fnCandidates = [
            $projectRoot . '/sql/missing_gab_routines.sql',
            $projectRoot . '/migrations/sql/missing_gab_routines.sql',
        ];
        $viewCandidates = [
            $projectRoot . '/sql/missing_kline_chunks_params_v.sql',
            $projectRoot . '/migrations/sql/missing_kline_chunks_params_v.sql',
        ];

        $fnPath = null;
        foreach ($fnCandidates as $p) {
            if (\file_exists($p)) { $fnPath = $p; break; }
        }
        $viewPath = null;
        foreach ($viewCandidates as $p) {
            if (\file_exists($p)) { $viewPath = $p; break; }
        }

        $this->abortIf($fnPath === null, 'Fichier SQL introuvable: missing_gab_routines.sql');
        $this->abortIf($viewPath === null, 'Fichier SQL introuvable: missing_kline_chunks_params_v.sql');

        $fnSql   = \file_get_contents($fnPath) ?: '';
        $viewSql = \file_get_contents($viewPath) ?: '';

        $this->abortIf($fnSql === '' , 'Contenu vide ou non lisible pour missing_gab_routines.sql');
        $this->abortIf($viewSql === '' , 'Contenu vide ou non lisible pour missing_kline_chunks_params_v.sql');

        foreach ($this->splitSqlStatements($fnSql) as $stmt) {
            $this->addSql($stmt);
        }
        foreach ($this->splitSqlStatements($viewSql) as $stmt) {
            $this->addSql($stmt);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP VIEW IF EXISTS missing_kline_chunks_params_v');
        $this->addSql("DROP FUNCTION IF EXISTS missing_kline_opentimes(text, text, timestamptz, timestamptz)");
        $this->addSql("DROP FUNCTION IF EXISTS missing_kline_opentimes(text, text, timestamp, timestamp)");
    }
}
