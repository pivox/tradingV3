<?php
# src/Logging/CustomLineFormatter.php
namespace App\Logging;

use Monolog\Formatter\LineFormatter;

class CustomLineFormatter extends LineFormatter
{
    public function __construct()
    {
        $output = "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n";
        $dateFormat = "d/m/Y H:i:s";
        parent::__construct($output, $dateFormat, true, true);
    }
}
