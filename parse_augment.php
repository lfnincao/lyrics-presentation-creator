<?php

require_once __DIR__ . '/lib/rtf_song_parser.php';

$rtfFile = $argc > 1 ? $argv[1] : 'BoP_Augment_text_RTF_20260427.rtf';
$outputDir = $argc > 2 ? $argv[2] : 'txt/augment';

if (!is_file($rtfFile)) {
    echo "RTF file not found: " . $rtfFile . "\n";
    exit(1);
}

$parser = new RtfSongParser();

try {
    $result = $parser->parseFile($rtfFile, $outputDir);
} catch (RuntimeException $e) {
    echo $e->getMessage() . "\n";
    exit(1);
}

echo "Parsed " . $result['songs_parsed'] . " songs\n";
echo "Wrote " . $result['files_written'] . " verse files to " . $outputDir . "\n";

if (!empty($result['warnings'])) {
    echo "\nWarnings:\n";
    foreach ($result['warnings'] as $warning) {
        echo "  - " . $warning . "\n";
    }
}
