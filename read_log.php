<?php
$logFile = 'storage/logs/laravel.log';
if (!file_exists($logFile)) {
    echo "Log file not found.";
    exit;
}

$lines = file($logFile);
$lastLines = array_slice($lines, -50);
foreach ($lastLines as $line) {
    if (strpos($line, 'Error subiendo Excel') !== false || strpos($line, 'Stack trace') !== false || strpos($line, 'Exception') !== false) {
        echo $line;
    }
}
