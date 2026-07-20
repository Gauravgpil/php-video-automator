<?php

namespace PhpVideoAutomator\Services;

class AssSubtitleService
{
    /**
     * Available $styleOptions keys:
     *
     * preset          string  'center_word' (default), 'classic', 'elegant_bottom', 'bold_top', 'cinematic', 'grampa_random'
     * fontname        string  Font family (e.g. 'Montserrat', 'Impact', 'Arial')
     * fontsize        int     Font size in points
     * bold            bool    Enable bold text
     * primary_color   string  Base text color — hex (#RRGGBB) or ASS (&HBBGGRR)
     * highlight_color string  Karaoke sweep color — hex or ASS
     * outline_color   string  Outline/border color — hex or ASS
     * back_color      string  Shadow color — hex or ASS
     * outline         int     Outline stroke width in pixels
     * shadow          int     Drop-shadow depth in pixels
     * alignment       int     ASS numpad layout: 2=bottom-center, 5=center, 8=top-center
     * margin_v        int     Vertical margin in pixels
     * margin_h        int     Horizontal margin in pixels
     * words_per_line  int     Max words per subtitle line
     * karaoke_mode    string  'kf' = smooth left-to-right sweep, 'k' = instant switch
     */
    protected function presetDefaults(string $preset): array
    {
        return match ($preset) {
            'center_word' => [
                'fontname'       => 'Montserrat',
                'fontsize'       => 72,
                'bold'           => true,
                'primary_color'  => '#FFFFFF',
                'highlight_color'=> '#FFD700',
                'outline_color'  => '#000000',
                'back_color'     => '#000000',
                'outline'        => 4,
                'shadow'         => 3,
                'alignment'      => 5,
                'margin_v'       => 0,
                'margin_h'       => 30,
                'words_per_line' => 4,
                'karaoke_mode'   => 'kf',
            ],
            'classic' => [
                'fontname'       => 'Arial',
                'fontsize'       => 60,
                'bold'           => true,
                'primary_color'  => '#FFFFFF',
                'highlight_color'=> '#00FFFF',
                'outline_color'  => '#000000',
                'back_color'     => '#000000',
                'outline'        => 3,
                'shadow'         => 2,
                'alignment'      => 2,
                'margin_v'       => 120,
                'margin_h'       => 30,
                'words_per_line' => 5,
                'karaoke_mode'   => 'kf',
            ],
            'elegant_bottom' => [
                'fontname'       => 'Verdana',
                'fontsize'       => 60,
                'bold'           => true,
                'primary_color'  => '#FFFFFF',
                'highlight_color'=> '#FFD700',
                'outline_color'  => '#000000',
                'back_color'     => '#000000',
                'outline'        => 3,
                'shadow'         => 2,
                'alignment'      => 2,
                'margin_v'       => 120,
                'margin_h'       => 30,
                'words_per_line' => 5,
                'karaoke_mode'   => 'kf',
            ],
            'bold_top' => [
                'fontname'       => 'Arial',
                'fontsize'       => 68,
                'bold'           => true,
                'primary_color'  => '#FFFF00',
                'highlight_color'=> '#FF4500',
                'outline_color'  => '#000000',
                'back_color'     => '#000000',
                'outline'        => 4,
                'shadow'         => 2,
                'alignment'      => 8,
                'margin_v'       => 80,
                'margin_h'       => 30,
                'words_per_line' => 4,
                'karaoke_mode'   => 'kf',
            ],
            'cinematic' => [
                'fontname'       => 'Impact',
                'fontsize'       => 68,
                'bold'           => false,
                'primary_color'  => '#FFFFFF',
                'highlight_color'=> '#FF0000',
                'outline_color'  => '#000000',
                'back_color'     => '#000000',
                'outline'        => 5,
                'shadow'         => 3,
                'alignment'      => 5,
                'margin_v'       => 0,
                'margin_h'       => 30,
                'words_per_line' => 4,
                'karaoke_mode'   => 'kf',
            ],
            'grampa_random' => [
                'fontname'       => ['Montserrat', 'Arial', 'Impact', 'Trebuchet MS', 'Tahoma'][array_rand(['Montserrat', 'Arial', 'Impact', 'Trebuchet MS', 'Tahoma'])],
                'fontsize'       => rand(55, 80),
                'bold'           => true,
                'primary_color'  => ['#FFFFFF', '#FFFF00', '#FF4500', '#00FFFF', '#00FF00'][array_rand(['#FFFFFF', '#FFFF00', '#FF4500', '#00FFFF', '#00FF00'])],
                'highlight_color'=> ['#FFD700', '#FF0000', '#00FFFF', '#FF69B4', '#ADFF2F'][array_rand(['#FFD700', '#FF0000', '#00FFFF', '#FF69B4', '#ADFF2F'])],
                'outline_color'  => '#000000',
                'back_color'     => '#000000',
                'outline'        => 4,
                'shadow'         => 2,
                'alignment'      => 5,
                'margin_v'       => 0,
                'margin_h'       => 30,
                'words_per_line' => 4,
                'karaoke_mode'   => 'kf',
            ],
            default => $this->presetDefaults('center_word'),
        };
    }

    public function generateAssSubtitles(array $words, array $styleOptions, string $outputFile, int $width, int $height): void
    {
        $preset  = $styleOptions['preset'] ?? 'center_word';
        $style   = array_merge($this->presetDefaults($preset), $styleOptions);

        $fontName     = (string) ($style['fontname']      ?? 'Montserrat');
        $fontSize     = (int)   ($style['fontsize']       ?? 72);
        $bold         = ((bool) ($style['bold']           ?? true)) ? -1 : 0;
        $outline      = (int)   ($style['outline']        ?? 4);
        $shadow       = (int)   ($style['shadow']         ?? 3);
        $alignment    = (int)   ($style['alignment']      ?? 5);
        $marginV      = (int)   ($style['margin_v']       ?? 0);
        $marginH      = (int)   ($style['margin_h']       ?? 30);
        $wordsPerLine = (int)   ($style['words_per_line'] ?? 4);
        $karaokeMode  = (string)($style['karaoke_mode']   ?? 'kf');

        $primaryColor   = $this->toAssColor($style['primary_color']  ?? '#FFFFFF');
        $highlightColor = $this->toAssColor($style['highlight_color'] ?? '#FFD700');
        $outlineColor   = $this->toAssColor($style['outline_color']   ?? '#000000');
        $backColor      = $this->toAssAlphaColor($style['back_color'] ?? '#000000', 0x90);

        $lines       = [];
        $currentLine = [];

        foreach ($words as $word) {
            $currentLine[] = $word;
            if (count($currentLine) >= $wordsPerLine || preg_match('/[.?!]\s*$/', trim($word['word']))) {
                $lines[]     = $currentLine;
                $currentLine = [];
            }
        }

        if (!empty($currentLine)) {
            $lines[] = $currentLine;
        }

        $ass  = "[Script Info]\n";
        $ass .= "ScriptType: v4.00+\n";
        $ass .= "Collisions: Normal\n";
        $ass .= "PlayResX: {$width}\n";
        $ass .= "PlayResY: {$height}\n";
        $ass .= "Timer: 100.0000\n\n";
        $ass .= "[V4+ Styles]\n";
        $ass .= "Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n";
        $ass .= "Style: Default,{$fontName},{$fontSize},{$primaryColor},{$highlightColor},{$outlineColor},{$backColor},{$bold},0,0,0,100,100,0,0,1,{$outline},{$shadow},{$alignment},{$marginH},{$marginH},{$marginV},1\n\n";
        $ass .= "[Events]\n";
        $ass .= "Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";

        foreach ($lines as $lineWords) {
            if (empty($lineWords)) {
                continue;
            }

            $lineStart   = (float) ($lineWords[0]['start'] ?? 0);
            $lineEnd     = (float) (end($lineWords)['end'] ?? $lineStart + 2.0);
            $karaokeText = '';

            foreach ($lineWords as $w) {
                $wStart      = (float) ($w['start'] ?? 0);
                $wEnd        = (float) ($w['end']   ?? $wStart + 0.3);
                $wDurCs      = max(1, (int) round(($wEnd - $wStart) * 100));
                $wText       = str_replace(['\\', '{', '}'], ['\\\\', '\{', '\}'], trim((string) $w['word']));
                $karaokeText .= "{{\\{$karaokeMode}{$wDurCs}}{$wText} ";
            }

            $ass .= 'Dialogue: 0,' . $this->formatAssTime($lineStart) . ',' . $this->formatAssTime($lineEnd) . ",Default,,0,0,0,,{$karaokeText}\n";
        }

        file_put_contents($outputFile, $ass);
    }

    protected function toAssColor(string $color): string
    {
        $color = trim($color);

        if (str_starts_with($color, '&H')) {
            $hex = strtoupper(ltrim(substr($color, 2), '0') ?: '0');
            return '&H' . str_pad($hex, 8, '0', STR_PAD_LEFT);
        }

        $hex = ltrim($color, '#');

        if (strlen($hex) === 6) {
            return '&H00' . strtoupper(substr($hex, 4, 2) . substr($hex, 2, 2) . substr($hex, 0, 2));
        }

        return '&H00FFFFFF';
    }

    protected function toAssAlphaColor(string $color, int $alpha = 0x00): string
    {
        $base      = $this->toAssColor($color);
        $alphaByte = strtoupper(sprintf('%02X', $alpha));

        return '&H' . $alphaByte . substr($base, 4);
    }

    protected function formatAssTime(float $seconds): string
    {
        $hours     = (int) floor($seconds / 3600);
        $minutes   = (int) floor(($seconds % 3600) / 60);
        $secs      = (int) floor($seconds % 60);
        $centisecs = (int) round(fmod($seconds, 1) * 100);

        return sprintf('%d:%02d:%02d.%02d', $hours, $minutes, $secs, $centisecs);
    }
}
