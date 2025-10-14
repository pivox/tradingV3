<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251014094500 extends AbstractMigration
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

            if (!$inSingle && !$inDouble && $ch === '$' && $next2 === '$') {
                $inDollar = !$inDollar;
                $current .= '$$';
                $i++;
                continue;
            }

            if (!$inDollar) {
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

    public function getDescription(): string
    {
        return 'Ajoute la fonction ingest_klines_json(jsonb) pour insérer un batch de klines.';
    }

    public function up(Schema $schema): void
    {
        $projectRoot = \dirname(__DIR__); // ../trading-app

        $candidates = [
            $projectRoot . '/sql/ingest_klines_json.sql',
            $projectRoot . '/migrations/sql/ingest_klines_json.sql',
        ];

        $filePath = null;
        foreach ($candidates as $p) {
            if (\file_exists($p)) { $filePath = $p; break; }
        }
        $this->abortIf($filePath === null, 'Fichier SQL introuvable: ingest_klines_json.sql');

        $sql = \file_get_contents($filePath) ?: '';
        $this->abortIf($sql === '', 'Contenu vide pour ingest_klines_json.sql');

        foreach ($this->splitSqlStatements($sql) as $stmt) {
            $this->addSql($stmt);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP FUNCTION IF EXISTS ingest_klines_json(jsonb)');
    }
}

