<?php

namespace App\Service;

interface AssignmentStorageInterface
{
    /**
     * Sauvegarde les assignations symbole -> worker
     */
    public function save(array $assignments): void;

    /**
     * Charge les assignations depuis le stockage
     */
    public function load(): array;

    /**
     * Efface toutes les assignations
     */
    public function clear(): void;
}
