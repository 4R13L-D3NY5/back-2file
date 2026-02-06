<?php
$path = base_path('.env');
if (file_exists($path)) {
    $content = file_get_contents($path);
    // Replace DB_PASSWORD line safely
    $newContent = preg_replace(
        '/^DB_PASSWORD=.*$/m',
        'DB_PASSWORD="q7=L)rheh4"',
        $content
    );
    file_put_contents($path, $newContent);
    echo "Successfully updated DB_PASSWORD in .env\n";
} else {
    echo ".env file not found!\n";
}
