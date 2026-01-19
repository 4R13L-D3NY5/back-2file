<?php

require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpWord\IOFactory;

$file = __DIR__ . '/../1. P.A. ANATOMIA HUMANA I  2026.docx';

if (!file_exists($file)) {
    die("File not found: $file\n");
}

try {
    $phpWord = IOFactory::load($file);
    echo "<h1>Structure Analysis provided by Antigravity</h1>\n";

    function recursivePrint($elements)
    {
        foreach ($elements as $element) {
            if ($element instanceof \PhpOffice\PhpWord\Element\TextRun) {
                echo "<strong>TextRun:</strong> ";
                foreach ($element->getElements() as $text) {
                    if ($text instanceof \PhpOffice\PhpWord\Element\Text) {
                        echo $text->getText();
                    }
                }
                echo "\n<br/>";
            } elseif ($element instanceof \PhpOffice\PhpWord\Element\Title) {
                echo "<strong>Title (Depth " . $element->getDepth() . "):</strong> " . $element->getText() . "\n<br/>";
            } elseif ($element instanceof \PhpOffice\PhpWord\Element\Text) {
                echo "<strong>Text:</strong> " . $element->getText() . "\n<br/>";
            } elseif ($element instanceof \PhpOffice\PhpWord\Element\Table) {
                echo "<strong>[TABLE START]</strong><br/>";
                foreach ($element->getRows() as $row) {
                    echo "  <strong>[ROW]</strong> ";
                    foreach ($row->getCells() as $cell) {
                        echo "[CELL]: ";
                        recursivePrint($cell->getElements());
                    }
                    echo "<br/>";
                }
                echo "<strong>[TABLE END]</strong><br/>";
            } elseif ($element instanceof \PhpOffice\PhpWord\Element\TextBreak) {
                echo "<br/>";
            } else {
                echo "<strong>Unknown Element:</strong> " . get_class($element) . "\n<br/>";
            }
        }
    }

    foreach ($phpWord->getSections() as $section) {
        recursivePrint($section->getElements());
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
}
