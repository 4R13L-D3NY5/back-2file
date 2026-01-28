<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/app/Services/ProgramaAnaliticoParser.php';

use App\Services\ProgramaAnaliticoParser;

$filePath = 'c:\Users\juanj\OneDrive\Escritorio\Academico\LENGUAJES DE PROGRAMACION DE ULTIMA GENERACION.docx';

if (!file_exists($filePath)) {
    die("File not found: $filePath\n");
}

echo "Testing Parser on file...\n";

try {
    $parser = new ProgramaAnaliticoParser();
    $result = $parser->parse($filePath);

    echo "Writing structured output to debug_output.txt...\n";

    file_put_contents('debug_output.txt', print_r($result, true));
    echo "Done.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
