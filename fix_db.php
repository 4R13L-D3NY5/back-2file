<?php
// Connect via Socket as maintenance user
$mysqli = new mysqli("localhost", "debian-sys-maint", "rQeRg6xw2DS32MpK", "mysql", 3306, "/var/run/mysqld/mysqld.sock");

if ($mysqli->connect_error) {
    // Try TCP if socket fails
    $mysqli = new mysqli("127.0.0.1", "debian-sys-maint", "rQeRg6xw2DS32MpK", "mysql");
    if ($mysqli->connect_error) {
        die("Connection failed: " . $mysqli->connect_error . "\n");
    }
}

echo "Connected as maintenance user.\n";

// Reset ROOT password to standard one
$sql = "ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY 'Unitepc2026!'";
if ($mysqli->query($sql) === TRUE) {
    echo "Root password reset successfully.\n";
    $mysqli->query("FLUSH PRIVILEGES");

    // Update .env
    $envPath = __DIR__ . '/.env';
    if (file_exists($envPath)) {
        $content = file_get_contents($envPath);
        $content = preg_replace('/^DB_PASSWORD=.*$/m', 'DB_PASSWORD="Unitepc2026!"', $content);
        $content = preg_replace('/^DB_USERNAME=.*$/m', 'DB_USERNAME=root', $content);
        file_put_contents($envPath, $content);
        echo ".env updated to use root:Unitepc2026!\n";
    }
} else {
    echo "Error resetting password: " . $mysqli->error . "\n";
}

$mysqli->close();
