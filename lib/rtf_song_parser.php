<?php

class RtfSongParser
{
    private $warnings = [];
    private $songsParsed = 0;
    private $filesWritten = 0;

    public function parseFile($rtfPath, $outputDir)
    {
        $this->warnings = [];
        $this->songsParsed = 0;
        $this->filesWritten = 0;

        $rtf = file_get_contents($rtfPath);
        if ($rtf === false) {
            throw new RuntimeException('Failed to read RTF file: ' . $rtfPath);
        }

        if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
            throw new RuntimeException('Failed to create output directory: ' . $outputDir);
        }

        $songs = $this->splitSongs($rtf);
        foreach ($songs as $song) {
            $this->writeSong($song, $outputDir);
        }

        return [
            'songs_parsed' => $this->songsParsed,
            'files_written' => $this->filesWritten,
            'warnings' => $this->warnings,
        ];
    }

    public function getWarnings()
    {
        return $this->warnings;
    }

    private function splitSongs($rtf)
    {
        $pattern = '/\\\\f0\\\\b\\\\fs37\\\\fsmilli18667\s+(PSALM|HYMN)\s+((?:[A-Za-z]\d+|\d+[A-Za-z]?))/';
        preg_match_all($pattern, $rtf, $matches, PREG_OFFSET_CAPTURE);

        $songs = [];
        $count = count($matches[0]);

        for ($i = 0; $i < $count; $i++) {
            $start = $matches[0][$i][1];
            $end = ($i + 1 < $count) ? $matches[0][$i + 1][1] : strlen($rtf);
            $songs[] = [
                'book' => $matches[1][$i][0],
                'id' => $matches[2][$i][0],
                'chunk' => substr($rtf, $start, $end - $start),
            ];
        }

        return $songs;
    }

    private function extractLyricBlocks($chunk)
    {
        preg_match_all(
            '/\\\\fs29\\\\fsmilli14667(.*?)\\\\fs32/s',
            $chunk,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        $blocks = [];
        $count = count($matches[0]);

        for ($i = 0; $i < $count; $i++) {
            $content = $matches[1][$i][0];
            $fs32End = $matches[0][$i][1] + strlen($matches[0][$i][0]);
            $after = substr($chunk, $fs32End);
            if (preg_match('/^\s*\\\\ulnone\s*/', $after)) {
                $content .= '\\ulnone ';
            }
            $blocks[] = $content;
        }

        return $blocks;
    }

    private function writeSong(array $song, $outputDir)
    {
        $book = $song['book'];
        $id = $song['id'];
        $blocks = $this->extractLyricBlocks($song['chunk']);

        if (empty($blocks)) {
            $this->warnings[] = 'No lyric blocks found for ' . $book . ' ' . $id;
            return;
        }

        $this->songsParsed++;
        $verses = [];
        $unnumberedLines = [];
        $refrainText = '';
        $lastVerseNum = null;
        $appendToLast = false;
        $expectingRefrain = false;

        foreach ($blocks as $block) {
            $text = trim($this->rtfBlockToText($block));
            if ($text === '') {
                continue;
            }

            if (preg_match('/^(\d+)\.\s/', $text, $match)) {
                $verseNum = (int)$match[1];
                $verses[$verseNum] = $text;
                $lastVerseNum = $verseNum;
                $appendToLast = false;
                $expectingRefrain = false;
            } elseif ($book === 'HYMN' && $id === '1') {
                $unnumberedLines[] = $text;
            } elseif (preg_match('/^After last stanza:?\s*$/i', $text)) {
                $appendToLast = true;
                $expectingRefrain = false;
            } elseif ($appendToLast && $lastVerseNum !== null) {
                $verses[$lastVerseNum] .= "\n" . $text;
                $appendToLast = false;
            } elseif ($expectingRefrain) {
                $refrainText = $text;
                if ($lastVerseNum !== null) {
                    $verses[$lastVerseNum] .= "\n" . $refrainText;
                }
                $expectingRefrain = false;
            } elseif (preg_match('/^Refrain:?\s*$/i', $text)) {
                $expectingRefrain = true;
            } elseif (preg_match('/^Refrain:?\s*(.+)$/is', $text, $match)) {
                $refrainContent = trim($match[1]);
                if ($refrainContent !== '') {
                    $refrainText = $refrainContent;
                }
                if ($refrainText !== '' && $lastVerseNum !== null) {
                    $verses[$lastVerseNum] .= "\n" . $refrainText;
                }
            } elseif ($book === 'HYMN' && empty($verses) && $lastVerseNum === null) {
                $verses[1] = $text;
                $lastVerseNum = 1;
            } elseif ($lastVerseNum !== null) {
                $verses[$lastVerseNum] .= "\n" . $text;
            } else {
                $this->warnings[] = 'Unnumbered lyric block in ' . $book . ' ' . $id . ': ' . substr($text, 0, 40) . '...';
            }
        }

        if ($book === 'HYMN' && $id === '1') {
            if (empty($unnumberedLines)) {
                $this->warnings[] = 'No lines found for HYMN 1';
                return;
            }
            $this->writeVerseFile($outputDir, $book, $id, 1, implode("\n", $unnumberedLines));
            return;
        }

        if (empty($verses)) {
            $this->warnings[] = 'No verses found for ' . $book . ' ' . $id;
            return;
        }

        ksort($verses, SORT_NUMERIC);
        foreach ($verses as $verseNum => $content) {
            $this->writeVerseFile($outputDir, $book, $id, $verseNum, $content);
        }
    }

    private function writeVerseFile($outputDir, $book, $id, $verseNum, $content)
    {
        $filename = $book . '-' . $id . '-' . $verseNum . '.txt';
        $path = rtrim($outputDir, '/\\') . DIRECTORY_SEPARATOR . $filename;
        $content = rtrim($content) . "\n";

        if (file_put_contents($path, $content) === false) {
            $this->warnings[] = 'Failed to write ' . $path;
            return;
        }

        $this->filesWritten++;
    }

    private function rtfBlockToText($rtf)
    {
        $len = strlen($rtf);
        $i = 0;
        $output = '';
        $ul = false;
        $italic = false;

        while ($i < $len) {
            $char = $rtf[$i];

            if ($char === '{') {
                $i++;
                continue;
            }

            if ($char === '}') {
                $output .= $this->closeMarkup($ul, $italic);
                $ul = false;
                $italic = false;
                $i++;
                continue;
            }

            if ($char !== '\\') {
                if ($char === "\x0B" || $char === "\n" || $char === "\r") {
                    if ($ul) {
                        $output .= '</i>';
                        $ul = false;
                    }
                    if ($italic) {
                        $output .= '</i>';
                        $italic = false;
                    }
                    if ($char !== "\r") {
                        $output .= "\n";
                    }
                    $i++;
                    continue;
                }

                $output .= $this->emitChar($char, $ul, $italic);
                $i++;
                continue;
            }

            $i++;
            if ($i >= $len) {
                break;
            }

            if ($rtf[$i] === "'") {
                $hex = substr($rtf, $i + 1, 2);
                $output .= $this->emitText($this->rtfHexToUtf8($hex), $ul, $italic);
                $i += 3;
                continue;
            }

            if ($rtf[$i] === '~') {
                $output .= $this->emitText(' ', $ul, $italic);
                $i++;
                continue;
            }

            if ($rtf[$i] === '\\') {
                $output .= $this->emitText('\\', $ul, $italic);
                $i++;
                continue;
            }

            if ($rtf[$i] === '_') {
                $output .= $this->emitText('-', $ul, $italic);
                $i++;
                continue;
            }

            if ($rtf[$i] === '*') {
                $i++;
                continue;
            }

            if ($rtf[$i] === '\n' || $rtf[$i] === '\r') {
                $i++;
                continue;
            }

            if (preg_match('/^u(-?\d+)\s?/', substr($rtf, $i), $match)) {
                $code = (int)$match[1];
                if ($code < 0) {
                    $code += 65536;
                }
                $output .= $this->emitText(mb_chr($code, 'UTF-8'), $ul, $italic);
                $i += strlen($match[0]);
                continue;
            }

            if (!preg_match('/^([a-zA-Z]+)(-?\d*) ?/', substr($rtf, $i), $match)) {
                $i++;
                continue;
            }

            $word = $match[1];
            $i += strlen($match[0]);

            switch ($word) {
                case 'ul':
                    if (!$ul) {
                        $output .= $this->closeMarkup($ul, $italic);
                        $italic = false;
                        $output .= '<i>';
                        $ul = true;
                    }
                    break;
                case 'ulnone':
                    if ($ul) {
                        $output .= '</i>';
                        $ul = false;
                    }
                    break;
                case 'i':
                    if (!$italic && !$ul) {
                        $output .= '<i>';
                        $italic = true;
                    }
                    break;
                case 'i0':
                    if ($italic) {
                        $output .= '</i>';
                        $italic = false;
                    }
                    break;
                case 'par':
                case 'line':
                    if ($ul) {
                        $output .= '</i>';
                        $ul = false;
                    }
                    if ($italic) {
                        $output .= '</i>';
                        $italic = false;
                    }
                    $output .= "\n";
                    break;
                case 'tab':
                    $output .= $this->emitText("\t", $ul, $italic);
                    break;
                case 'v':
                    if ($ul) {
                        $output .= '</i>';
                        $ul = false;
                    }
                    if ($italic) {
                        $output .= '</i>';
                        $italic = false;
                    }
                    $output .= "\n";
                    break;
                default:
                    break;
            }
        }

        $output .= $this->closeMarkup($ul, $italic);
        $output = str_replace("\x0B", "\n", $output);
        $output = preg_replace("/\n{3,}/", "\n\n", $output);
        $output = preg_replace('/<i><\/i>\s*/', '', $output);

        return $output;
    }

    private function emitChar($char, &$ul, &$italic)
    {
        return $this->emitText($char, $ul, $italic);
    }

    private function emitText($text, &$ul, &$italic)
    {
        if ($text === '') {
            return '';
        }

        if ($ul || $italic) {
            return $text;
        }

        return $text;
    }

    private function closeMarkup($ul, $italic)
    {
        $closing = '';
        if ($ul) {
            $closing .= '</i>';
        }
        if ($italic) {
            $closing .= '</i>';
        }
        return $closing;
    }

    private function rtfHexToUtf8($hex)
    {
        if (!preg_match('/^[0-9a-fA-F]{2}$/', $hex)) {
            return '';
        }

        $byte = chr(hexdec($hex));
        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($byte, 'UTF-8', 'Windows-1252');
        }

        return $byte;
    }
}
