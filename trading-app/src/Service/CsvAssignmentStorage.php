<?php

namespace App\Service;

final class CsvAssignmentStorage implements AssignmentStorageInterface
{
    public function __construct(
        private string $filePath
    ) {
        // Créer le répertoire si nécessaire
        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    public function save(array $assignments): void
    {
        $handle = fopen($this->filePath, 'w');
        if (!$handle) {
            throw new \RuntimeException("Impossible d'écrire dans le fichier: {$this->filePath}");
        }

        // En-tête CSV
        fputcsv($handle, ['symbol', 'worker', 'assigned_at']);

        // Données
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        foreach ($assignments as $symbol => $worker) {
            fputcsv($handle, [$symbol, $worker, $now]);
        }

        fclose($handle);
    }

    public function load(): array
    {
        if (!file_exists($this->filePath)) {
            return [];
        }

        $handle = fopen($this->filePath, 'r');
        if (!$handle) {
            throw new \RuntimeException("Impossible de lire le fichier: {$this->filePath}");
        }

        $assignments = [];
        
        // Ignorer l'en-tête
        fgetcsv($handle);

        // Lire les données
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) >= 2) {
                $assignments[$row[0]] = $row[1]; // symbol => worker
            }
        }

        fclose($handle);
        return $assignments;
    }

    public function clear(): void
    {
        if (file_exists($this->filePath)) {
            unlink($this->filePath);
        }
    }
}
