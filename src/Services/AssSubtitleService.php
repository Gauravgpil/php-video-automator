<?php

namespace PhpVideoAutomator\Services;

class AssSubtitleService
{
    public function generateAssSubtitles(array $words, array $styleOptions, string $outputFile, int $width, int $height): void
    {
        $fontSize = $styleOptions['fontsize'] ?? 60;
        if (!$fontSize) {
            $fontSize = 60;
        }

        $fontColorHex = '&H00FFFFFF';
        $highlightHex = '&H0000FFFF';
        $fontName = 'Arial';
        
        $preset = $styleOptions['preset'] ?? 'classic';
        if ($preset === 'grampa_random') {
            $colors = ['&H00FFFFFF', '&H0000FFFF', '&H0000D7FF', '&H00FF0000', '&H0000FF00'];
            $fonts = ['Arial', 'Verdana', 'Impact', 'Trebuchet MS', 'Tahoma'];
            $fontColorHex = $colors[array_rand($colors)];
            $highlightHex = $colors[array_rand($colors)];
            while ($highlightHex === $fontColorHex) {
                $highlightHex = $colors[array_rand($colors)];
            }
            $fontSize = rand(45, 75);
            $fontName = $fonts[array_rand($fonts)];
        } elseif ($preset === 'elegant_bottom') {
            $fontColorHex = '&H00FFFFFF';
            $highlightHex = '&H0000D7FF';
            $fontName = 'Verdana';
        } elseif ($preset === 'cinematic') {
            $fontName = 'Impact';
            $fontSize = 45;
        }

        $lines = [];
        $currentLine = [];
        
        foreach ($words as $word) {
            $currentLine[] = $word;
            if (count($currentLine) >= 6 || preg_match('/[.?!]$/', $word['word'])) {
                $lines[] = $currentLine;
                $currentLine = [];
            }
        }
        if (!empty($currentLine)) {
            $lines[] = $currentLine;
        }

        $assContent = "[Script Info]\n";
        $assContent .= "ScriptType: v4.00+\n";
        $assContent .= "PlayResX: {$width}\n";
        $assContent .= "PlayResY: {$height}\n\n";

        $assContent .= "[V4+ Styles]\n";
        $assContent .= "Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n";
        $assContent .= "Style: Default,{$fontName},{$fontSize},{$fontColorHex},&H000000FF,&H00000000,&H80000000,-1,0,0,0,100,100,0,0,1,3,2,2,30,30,150,1\n\n";

        $assContent .= "[Events]\n";
        $assContent .= "Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";

        foreach ($lines as $lineWords) {
            foreach ($lineWords as $index => $highlightedWord) {
                $startTime = $this->formatAssTime($highlightedWord['start']);
                $nextWord = $lineWords[$index + 1] ?? null;
                $endTime = $nextWord ? $this->formatAssTime($nextWord['start']) : $this->formatAssTime($highlightedWord['end']);

                $text = '';
                foreach ($lineWords as $i => $w) {
                    if ($i === $index) {
                        $text .= "{\\c{$highlightHex}}" . $w['word'] . "{\\c} ";
                    } else {
                        $text .= $w['word'] . " ";
                    }
                }
                
                $assContent .= "Dialogue: 0,{$startTime},{$endTime},Default,,0,0,0,,{$text}\n";
            }
        }

        file_put_contents($outputFile, $assContent);
    }

    protected function formatAssTime(float $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds / 60) % 60);
        $secs = floor($seconds % 60);
        $centisecs = floor(fmod($seconds, 1) * 100);
        
        return sprintf("%d:%02d:%02d.%02d", $hours, $minutes, $secs, $centisecs);
    }
}
