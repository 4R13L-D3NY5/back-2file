<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/app/Services/DocumentParserService.php';

use App\Services\DocumentParserService;
use Illuminate\Http\UploadedFile;

// Mock UploadedFile
class MockFile extends UploadedFile
{
    public function __construct($path, $originalName)
    {
        parent::__construct($path, $originalName, null, null, true); // true = test mode
    }
}

// Mock Log Facade
if (!class_exists('Illuminate\Support\Facades\Log')) {
    class_alias('MockLog', 'Illuminate\Support\Facades\Log');
}

class MockLog
{
    public static function info($msg)
    { /* echo "INFO: $msg\n"; */
    }
    public static function error($msg)
    {
        echo "ERROR: $msg\n";
    }
    public static function warning($msg)
    {
        echo "WARN: $msg\n";
    }
}


$realPath = 'c:\Users\juanj\OneDrive\Escritorio\Academico\LENGUAJES DE PROGRAMACION DE ULTIMA GENERACION.docx';

if (!file_exists($realPath)) {
    die("File not found: $realPath\n");
}

echo "Testing DocumentParserService on file...\n";

try {
    // We need to pass an UploadedFile instance
    $file = new MockFile($realPath, 'doc.docx');

    $parser = new DocumentParserService();
    // parseWord expects UploadedFile
    $data = $parser->parseWord($file);

    $out = "Structure found:\n";
    if (isset($data['estructura_unidades'])) {
        $out .= print_r($data['estructura_unidades'], true);
    } else {
        $out .= "No 'estructura_unidades' key found.\n";
    }

    $out .= "\n=== Data Dump ===\n";
    // echo all keys
    foreach ($data as $k => $v) {
        if (!is_array($v)) $out .= "$k: " . substr($v, 0, 50) . "...\n";
        else $out .= "$k: [Array " . count($v) . "]\n";
    }

    file_put_contents('debug_output_service.txt', $out);
    echo "Done.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
    file_put_contents('debug_output_service.txt', "Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
}
