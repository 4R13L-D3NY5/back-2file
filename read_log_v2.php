<?php
$logFile = 'storage/logs/laravel.log';
if (!file_exists($logFile)) exit("No log.\n");
$lines = file($logFile);
$lastLines = array_slice($lines, -100);
foreach ($lastLines as $line) {
    if (strpos($line, 'local.ERROR') !== false) {
        echo trim($line) . "\n";
    }
}
