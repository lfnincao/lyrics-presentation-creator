<?php
include "settings/presentationSettings.php";

// Read input.txt file
$psalmsAndHymns = file('input.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

if ($psalmsAndHymns === false) {
    echo "\nFailed to open input.txt file\n";
    exit;
}

if (count($psalmsAndHymns) === 0) {
    echo "\ninput.txt is empty\n";
    exit;
}

// Remove blank spaces from each line
$psalmsAndHymns = array_map('trim', $psalmsAndHymns);

$allSongVersesInTxt = '';
$pageNumber = 0;

// Loop through each psalm/hymn
foreach ($psalmsAndHymns as $psalmOrHymn) {
    // Check if this is a separator line (contains only dashes)
    if (preg_match('/^-+$/', trim($psalmOrHymn))) {
        $allSongVersesInTxt .= getEmptySlideXml(++$pageNumber);
        continue;
    }

    // flexible parsing: find H or P (case insensitive), then find first number (hymn/psalm number)
    // Then extract any subsequent numbers (verses)
    $matches = [];
    if (!preg_match('/[hHpP].*?((?:[A-Za-z]\d+|\d+[A-Za-z]?))(.*)/i', $psalmOrHymn, $matches)) {
        echo "\nSkipped unrecognized line: " . $psalmOrHymn . "\n";
        continue;
    }

    // Determine if it's a hymn or psalm based on the first H/P found
    $firstChar = '';
    if (preg_match('/[hHpP]/', $psalmOrHymn, $charMatch)) {
        $firstChar = strtolower($charMatch[0]);
    }

    if ($firstChar === 'h') {
        $book = 'HYMN';
    } else {
        $book = 'PSALM';
    }

    $number = $matches[1];
    $remainingText = isset($matches[2]) ? $matches[2] : '';

    // Extract all numbers and ranges from the remaining text as verses
    $verses = [];
    if (!empty($remainingText)) {
        // First, find all ranges (e.g., 1-4) and individual numbers
        preg_match_all('/(\d+)\s*-\s*(\d+)|\d+/', $remainingText, $verseMatches, PREG_SET_ORDER);

        foreach ($verseMatches as $match) {
            if (isset($match[2])) {
                // This is a range (e.g., 1-4)
                $start = (int)$match[1];
                $end = (int)$match[2];
                // Add all numbers in the range
                for ($i = $start; $i <= $end; $i++) {
                    $verses[] = $i;
                }
            } else {
                // This is a single number
                $verses[] = (int)$match[0];
            }
        }
    }

    if (empty($verses)) {
        $verses = getAllVerseNumbers($book, $number);
        if (empty($verses)) {
            echo "\nNo verse files found for " . $book . ' ' . $number . "\n";
            continue;
        }
    }

    // Loop through each verse
    $versesPerSlide = 0;
    $slideContent = '';

    foreach ($verses as $verse) {
        $versePath = resolveVersePath($book, $number, $verse);
        if ($versePath === null) {
            echo "\nMissing verse file: " . $book . '-' . $number . '-' . trim($verse) . ".txt\n";
            continue;
        }

        $verseTxt = file_get_contents($versePath);
        if ($verseTxt === false) {
            echo "\nFailed to read verse file: " . $versePath . "\n";
            continue;
        }

        if ($book == "HYMN" && $number == 1) {
            $verseLines = explode("\n", $verseTxt);
            $part1 = array_slice($verseLines, 0, 9);
            $part2 = array_slice($verseLines, 9);

            $allSongVersesInTxt .= getSlideXml(implode("\n", $part1), ++$pageNumber, $book, $number, [1]);
            $allSongVersesInTxt .= getSlideXml(implode("\n", $part2), ++$pageNumber, $book, $number, [1]);
            $slideContent = '';
            $versesPerSlide = 0;
            break;
        }

        $verseLineCount = countNonemptyLines($verseTxt);

        if ($versesPerSlide + $verseLineCount > 10 && $versesPerSlide != 0) {
            // We need to start a new slide
            $allSongVersesInTxt .= getSlideXml($slideContent, ++$pageNumber, $book, $number, $verses);
            $slideContent = '';
            $versesPerSlide = 0;
        }

        $versesPerSlide += $verseLineCount;
        $slideContent .= $verseTxt . "\n";
    }

    if ($versesPerSlide != 0) {
        // We still have content to add to the last slide
        $allSongVersesInTxt .= getSlideXml($slideContent, ++$pageNumber, $book, $number, $verses);
    }
}

if (!$allSongVersesInTxt) {
    echo "\nNo slides were generated. Check input.txt and verse files.\n";
    exit;
}

$contentFile = 'content.xml';
$written = file_put_contents($contentFile, $beginPresentation . $allSongVersesInTxt . $endPresentation);
if ($written === false) {
    echo "\nFailed to create file " . $contentFile . "\n";
    exit;
}
echo $contentFile . " created successfully";

$zip = new ZipArchive();
$templateFile = "settings/template";
$presentationFile = "Lyrics.odp";

// First, copy the template to create the new presentation file
if (!copy($templateFile, $presentationFile)) {
    echo "\nFailed to create presentation file from template";
    exit;
}

// Now open the newly created presentation file and modify it
if ($zip->open($presentationFile, ZipArchive::CREATE) === true) {
    $zip->addFile($contentFile);
    $zip->close();

    // Clean up the temporary content file
    if (file_exists($contentFile)) {
        unlink($contentFile);
        echo "\nFile " . $contentFile . ' deleted successfully';
    } else {
        echo "\nFile " . $contentFile . ' does not exist';
        exit;
    }
    echo "\nPresentation file created: " . $presentationFile . "\n";
} else {
    echo "\nFailed to modify presentation file: " . $presentationFile;
    // Clean up the failed presentation file
    if (file_exists($presentationFile)) {
        unlink($presentationFile);
    }
}

function countNonemptyLines($text)
{
    $count = 0;
    foreach (explode("\n", $text) as $line) {
        if (trim($line) !== '') {
            $count++;
        }
    }
    return $count;
}

function resolveVersePath($book, $number, $verse)
{
    $file = $book . '-' . $number . '-' . trim($verse) . '.txt';
    $mainPath = 'txt/' . $file;
    if (is_file($mainPath)) {
        return $mainPath;
    }

    $augmentPath = 'txt/augment/' . $file;
    if (is_file($augmentPath)) {
        return $augmentPath;
    }

    return null;
}

function getVerseNumbersFromDir($dir, $book, $number)
{
    $files = glob($dir . $book . '-' . $number . '-*.txt');
    $verses = [];
    $prefix = $book . '-' . $number . '-';

    foreach ($files as $file) {
        $basename = basename($file, '.txt');
        if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', $basename, $match)) {
            $verses[] = (int)$match[1];
        }
    }

    sort($verses, SORT_NUMERIC);
    return $verses;
}

function getAllVerseNumbers($book, $number)
{
    $mainVerses = getVerseNumbersFromDir('txt/', $book, $number);
    if (!empty($mainVerses)) {
        return $mainVerses;
    }

    return getVerseNumbersFromDir('txt/augment/', $book, $number);
}

function formatLyricLineForXml($line)
{
    $parts = preg_split('/(<i>|<\/i>)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE);
    $xml = '';
    $inItalic = false;

    foreach ($parts as $part) {
        if ($part === '<i>') {
            $inItalic = true;
            continue;
        }
        if ($part === '</i>') {
            $inItalic = false;
            continue;
        }
        if ($part === '') {
            continue;
        }

        $style = $inItalic ? 'T3' : 'T2';
        $xml .= '<text:span text:style-name="' . $style . '">' .
            htmlspecialchars($part, ENT_XML1 | ENT_QUOTES, 'UTF-8') .
            '</text:span>';
    }

    return $xml;
}

function getSlideXml($verseTxt, $pageNumber, $book, $number, $verses)
{
    if (strtoupper($book) == "HYMN" && $number == '1') {
        $title = "Hymn " . $number . ": The Apostles' Creed";
    } else {
        $title = ucfirst(strtolower($book)) . ' ' . $number . ': ' . implode(", ", $verses);
    }

    $title = htmlspecialchars($title, ENT_XML1 | ENT_QUOTES, 'UTF-8');

    $beginSlide = '<draw:page draw:name="page'.$pageNumber.'" draw:style-name="dp1" draw:master-page-name="Master1-Office-Theme" '.
    'presentation:presentation-page-layout-name="AL1T0"><office:forms form:automatic-focus="false" form:apply-design-mode="false"/>'.
    '<draw:frame presentation:style-name="pr1" draw:text-style-name="P2" draw:layer="layout" svg:width="16.276cm" svg:height="1.762cm"'.
    ' svg:x="1.144cm" svg:y="0.179cm" presentation:class="title" presentation:user-transformed="true"><draw:text-box><text:p '.
    'text:style-name="P1"><text:span text:style-name="T1">'. $title .'</text:span></text:p></draw:text-box></draw:frame>'.
    '<draw:frame presentation:style-name="pr2" draw:text-style-name="P4" draw:layer="layout" svg:width="25.189cm" '.
    'svg:height="12.4cm" svg:x="1.124cm" svg:y="2.2cm" presentation:class="subtitle"'.
    ' presentation:user-transformed="true"><draw:text-box>';

    $endSlide = '</draw:text-box></draw:frame><presentation:notes draw:style-name="dp2"><draw:page-thumbnail draw:style-name="gr1"'.
    ' draw:layer="layout" svg:width="18.624cm" svg:height="10.476cm" svg:x="1.482cm" svg:y="2.123cm" draw:page-number="'.$pageNumber.
    '" presentation:class="page"/><draw:frame presentation:style-name="pr3" draw:text-style-name="P5" draw:layer="layout" '.
    'svg:width="17.271cm" svg:height="12.572cm" svg:x="2.159cm" svg:y="13.271cm" presentation:class="notes" '.
    'presentation:placeholder="true"><draw:text-box/></draw:frame></presentation:notes></draw:page>';


    $lines = explode("\n", $verseTxt);
    $formattedLines = '';
    $count = 0;
    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }
        if ($count > 0 && preg_match('/^\d/', $line)) {
            $formattedLines .= '<text:p text:style-name="P3"><text:span text:style-name="T2"></text:span></text:p>';
        }
        $formattedLines .= '<text:p text:style-name="P3">' . formatLyricLineForXml($line) . '</text:p>';
        $count++;
    }

    return $beginSlide . $formattedLines . $endSlide;
}

function getEmptySlideXml($pageNumber)
{
    $beginSlide = '<draw:page draw:name="page'.$pageNumber.'" draw:style-name="dp1" draw:master-page-name="Master1-Office-Theme" '.
    'presentation:presentation-page-layout-name="AL1T0"><office:forms form:automatic-focus="false" form:apply-design-mode="false"/>'.
    '<draw:frame presentation:style-name="pr1" draw:text-style-name="P2" draw:layer="layout" svg:width="16.276cm" svg:height="1.762cm"'.
    ' svg:x="1.144cm" svg:y="0.179cm" presentation:class="title" presentation:user-transformed="true"><draw:text-box><text:p '.
    'text:style-name="P1"><text:span text:style-name="T1"></text:span></text:p></draw:text-box></draw:frame>'.
    '<draw:frame presentation:style-name="pr2" draw:text-style-name="P4" draw:layer="layout" svg:width="25.189cm" '.
    'svg:height="12.4cm" svg:x="1.124cm" svg:y="2.2cm" presentation:class="subtitle"'.
    ' presentation:user-transformed="true"><draw:text-box>';

    $endSlide = '</draw:text-box></draw:frame><presentation:notes draw:style-name="dp2"><draw:page-thumbnail draw:style-name="gr1"'.
    ' draw:layer="layout" svg:width="18.624cm" svg:height="10.476cm" svg:x="1.482cm" svg:y="2.123cm" draw:page-number="'.$pageNumber.
    '" presentation:class="page"/><draw:frame presentation:style-name="pr3" draw:text-style-name="P5" draw:layer="layout" '.
    'svg:width="17.271cm" svg:height="12.572cm" svg:x="2.159cm" svg:y="13.271cm" presentation:class="notes" '.
    'presentation:placeholder="true"><draw:text-box/></draw:frame></presentation:notes></draw:page>';

    return $beginSlide . $endSlide;
}
