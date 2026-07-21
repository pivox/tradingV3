<?php

declare(strict_types=1);

namespace App\Trading\Paper\Runtime;

interface PaperDatabaseInspectorInterface
{
    public function inspect(): PaperDatabaseInspection;
}
