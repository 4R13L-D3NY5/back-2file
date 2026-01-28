<?php

$line = "TEMA Nº 1.- INTRODUCCIÓN A LA PROGRAMACIÓN DE ULTIMA GENERACIÓN.";

// Current Regex in DocumentParserService
$regex1 = '/^TEMA\s*(?:N[º°]?\s*)?(\d+)\s*[:\.\-]+\s*(.*)/i';

// Regex from ProgramaAnaliticoParser
$regex2 = '/^TEMA\s*Nº\s*(\d+)\s*[:\.\-]+\s*(.*)/i';

echo "Testing string: '$line'\n";

echo "Regex 1 (Current): ";
if (preg_match($regex1, $line, $matches)) {
    echo "MATCH! Num: " . $matches[1] . "\n";
} else {
    echo "NO MATCH.\n";
}

echo "Regex 2 (Old): ";
if (preg_match($regex2, $line, $matches)) {
    echo "MATCH! Num: " . $matches[1] . "\n";
} else {
    echo "NO MATCH.\n";
}

// Try strict encoding handling
$line_utf8 = "TEMA N\xC2\xBA 1.- TEST"; // Explicit UTF-8 for Nº
echo "Testing UTF8 String: '$line_utf8'\n";
if (preg_match($regex1 . 'u', $line_utf8, $matches)) {
    echo "MATCH UTF8 w/ u flag! Num: " . $matches[1] . "\n";
} else {
    echo "NO MATCH UTF8.\n";
}
