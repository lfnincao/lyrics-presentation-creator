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
$documentSongs = [];
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
    $documentVerses = [];
    $includedVerses = [];

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

        $documentVerses[] = $verseTxt;
        $includedVerses[] = $verse;

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
    if ($documentVerses) {
        $documentSongs[] = ['title' => getSongTitle($book, $number, $includedVerses), 'verses' => $documentVerses];
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
    if ($documentSongs) {
        $documentFile = 'Lyrics.odt';
        if (writeLyricsOdt($documentFile, $documentSongs)) {
            echo "Text document created: " . $documentFile . "\n";
        } else {
            echo "Failed to create text document: " . $documentFile .
                " (close it if it is open and try again).\n";
        }
    }
} else {
    echo "\nFailed to modify presentation file: " . $presentationFile;
    // Clean up the failed presentation file
    if (file_exists($presentationFile)) {
        unlink($presentationFile);
    }
}

// An ODT is a ZIP archive with a stored mimetype entry and XML document parts.
function writeLyricsOdt($filename, $songs)
{
    $titleProperties = '<style:paragraph-properties ' .
        'fo:margin-left="0cm" fo:margin-right="0cm" fo:margin-top="0.35cm" ' .
        'fo:margin-bottom="0.25cm" fo:keep-with-next="always"/>' .
        '<style:text-properties fo:font-family="Helvetica" fo:font-size="26pt" fo:font-weight="bold"/>';
    $content = '<?xml version="1.0" encoding="UTF-8"?>' .
        '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" ' .
        'xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" ' .
        'xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" ' .
        'xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" office:version="1.2">' .
        '<office:automatic-styles>' .
        '<style:style style:name="SongTitle" style:family="paragraph">' . $titleProperties . '</style:style>' .
        '<style:style style:name="FirstSongTitle" style:family="paragraph" ' .
        'style:master-page-name="Standard">' . $titleProperties . '</style:style>' .
        '<style:style style:name="Lyric" style:family="paragraph"><style:paragraph-properties ' .
        'fo:margin-left="0cm" fo:margin-right="0cm" fo:margin-top="0cm" ' .
        'fo:margin-bottom="0cm" fo:line-height="130%" fo:keep-with-next="always"/>' .
        '<style:text-properties fo:font-family="Helvetica" fo:font-size="20pt" fo:font-weight="bold"/>' .
        '</style:style>' .
        '<style:style style:name="Stanza" style:family="paragraph"><style:paragraph-properties ' .
        'fo:margin-left="0cm" fo:margin-right="0cm" fo:margin-top="0cm" ' .
        'fo:margin-bottom="0.35cm" fo:line-height="130%"/>' .
        '<style:text-properties fo:font-family="Helvetica" fo:font-size="20pt" fo:font-weight="bold"/>' .
        '</style:style>' .
        '<style:style style:name="Italic" style:family="text"><style:text-properties fo:font-style="italic"/>' .
        '</style:style></office:automatic-styles><office:body><office:text>';

    foreach ($songs as $index => $song) {
        $titleStyle = $index === 0 ? 'FirstSongTitle' : 'SongTitle';
        $content .= '<text:h text:style-name="' . $titleStyle . '" text:outline-level="1">' .
            htmlspecialchars($song['title'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</text:h>';
        foreach ($song['verses'] as $verse) {
            $lines = preg_split('/\R/u', trim($verse));
            $lineCount = count($lines);
            foreach ($lines as $index => $line) {
                $style = $index === $lineCount - 1 ? 'Stanza' : 'Lyric';
                $content .= '<text:p text:style-name="' . $style . '">' . odtLyricLine(trim($line)) . '</text:p>';
            }
        }
    }
    $content .= '</office:text></office:body></office:document-content>';

    $styles = '<?xml version="1.0" encoding="UTF-8"?>' .
        '<office:document-styles xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" ' .
        'xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" ' .
        'xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" office:version="1.2">' .
        '<office:styles><style:default-style style:family="paragraph"><style:text-properties ' .
        'fo:font-family="Helvetica" fo:font-size="22pt" fo:font-weight="bold"/>' .
        '<style:paragraph-properties fo:margin-left="0.5cm" fo:margin-right="0.5cm"/>' .
        '</style:default-style></office:styles><office:automatic-styles>' .
        '<style:page-layout style:name="Standard"><style:page-layout-properties ' .
        'fo:page-width="21cm" fo:page-height="29.7cm" style:print-orientation="portrait" ' .
        'fo:margin-left="0.5cm" fo:margin-right="0.5cm" fo:margin-top="1.5cm" fo:margin-bottom="1.5cm"/>' .
        '</style:page-layout></office:automatic-styles><office:master-styles>' .
        '<style:master-page style:name="Standard" style:page-layout-name="Standard"/>' .
        '</office:master-styles></office:document-styles>';
    $manifest = '<?xml version="1.0" encoding="UTF-8"?>' .
        '<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" ' .
        'manifest:version="1.2"><manifest:file-entry manifest:full-path="/" ' .
        'manifest:media-type="application/vnd.oasis.opendocument.text"/>' .
        '<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>' .
        '<manifest:file-entry manifest:full-path="styles.xml" manifest:media-type="text/xml"/>' .
        '</manifest:manifest>';
    $zip = new ZipArchive();
    if ($zip->open($filename, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return false;
    }
    $ok = $zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.text') &&
        $zip->setCompressionName('mimetype', ZipArchive::CM_STORE) &&
        $zip->addFromString('content.xml', $content) &&
        $zip->addFromString('styles.xml', $styles) &&
        $zip->addFromString('META-INF/manifest.xml', $manifest);
    return $zip->close() && $ok;
}

function odtLyricLine($line)
{
    $parts = preg_split('/(<i>|<\/i>)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE);
    $xml = '';
    $italic = false;
    foreach ($parts as $part) {
        if ($part === '<i>') {
            $italic = true;
        } elseif ($part === '</i>') {
            $italic = false;
        } elseif ($part !== '') {
            $text = htmlspecialchars($part, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $xml .= $italic ? '<text:span text:style-name="Italic">' . $text . '</text:span>' : $text;
        }
    }
    return $xml;
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

function getSongTitle($book, $number, $verses)
{
    if (strtoupper($book) === 'HYMN' && $number == '1') {
        return "Hymn " . $number . ": The Apostles' Creed";
    }
    return ucfirst(strtolower($book)) . ' ' . $number . ': ' . implode(', ', $verses);
}

function getSlideXml($verseTxt, $pageNumber, $book, $number, $verses)
{
    $title = htmlspecialchars(getSongTitle($book, $number, $verses), ENT_XML1 | ENT_QUOTES, 'UTF-8');

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
