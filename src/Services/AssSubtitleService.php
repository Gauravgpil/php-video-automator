<?php

namespace PhpVideoAutomator\Services;

/**
 * AssSubtitleService
 *
 * Generates professional ASS (Advanced SubStation Alpha) subtitle files
 * with word-by-word karaoke-style highlighting, fully configurable via
 * the $styleOptions array so users can customise every visual detail.
 *
 * Supported $styleOptions keys
 * ─────────────────────────────────────────────────────────────────────
 *  preset            string  Named style preset. Built-in presets:
 *                              'center_word'   – ⭐ centered, bold, karaoke (default)
 *                              'classic'       – bottom captions, white text
 *                              'elegant_bottom'– bottom captions, Verdana, yellow highlight
 *                              'bold_top'      – top captions, yellow on red
 *                              'cinematic'     – center, Impact font, red highlight
 *                              'grampa_random' – random colors/fonts per job
 *
 *  fontname          string  Font family name (e.g. 'Montserrat', 'Impact', 'Arial')
 *  fontsize          int     Font size in points (e.g. 72)
 *  bold              bool    true = bold text
 *
 *  primary_color     string  Base text color as hex (#RRGGBB) or ASS &HBBGGRR
 *  highlight_color   string  Karaoke sweep color as hex or ASS &HBBGGRR
 *  outline_color     string  Outline/border color as hex or ASS &HBBGGRR
 *  back_color        string  Shadow/background color as hex or ASS &HBBGGRR
 *
 *  outline           int     Outline stroke width in pixels (0 = none)
 *  shadow            int     Drop-shadow depth in pixels (0 = none)
 *
 *  alignment         int     ASS alignment (1-9, numpad layout):
 *                              1=BL 2=BC 3=BR  4=ML 5=MC 6=MR  7=TL 8=TC 9=TR
 *                              5 = center-of-screen (default for center_word)
 *                              2 = bottom-center (classic captions)
 *                              8 = top-center
 *  margin_v          int     Vertical margin in pixels (used with alignment 2 or 8)
 *  margin_h          int     Horizontal margin in pixels
 *
 *  words_per_line    int     Max words before starting a new subtitle line (default 4)
 *  karaoke_mode      string  'kf' = smooth sweep (default), 'k' = instant switch
 * ─────────────────────────────────────────────────────────────────────
 */
class AssSubtitleService
{
    /**
     * Build preset defaults. Each preset returns a complete style array;
     * any key can be overridden by passing it directly in $styleOptions.
     */
    protected function presetDefaults(string $preset): array
    {
        return match ($preset) {
            // ── Center-of-screen word-by-word karaoke (professional social-video look) ──
            'center_word' => [
                'fontname'       => 'Montserrat',
                'fontsize'       => 72,
                'bold'           => true,
                'primary_color'  => '#FFFFFF',
                'highlight_color'=> '#FFD700',   // gold sweep
                'outline_color'  => '#000000',
                'back_color'     => '#000000',   // used as shadow color
                'outline'        => 4,
                'shadow'         => 3,
                'alignment'      => 5,           // center of screen
                'margin_v'       => 0,
                'margin_h'       => 30,
                'words_per_line' => 4,
                'karaoke_mode'   => 'kf',
            ],

            // ── Bottom captions – plain white ──
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

            // ── Bottom captions – gold highlight, Verdana ──
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

            // ── Top captions – bold yellow ──
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

            // ── Cinematic center – Impact, red highlight ──
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

            // ── Random colors + fonts – fun/viral style ──
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

            // ── Unknown preset → fall back to center_word ──
            default => $this->presetDefaults('center_word'),
        };
    }

    /**
     * Main entry point.
     *
     * @param  array  $words        Word-timestamp array: [['word'=>'Hello','start'=>0.0,'end'=>0.4], ...]
     * @param  array  $styleOptions User-supplied style overrides (see class docblock)
     * @param  string $outputFile   Absolute path for the .ass output file
     * @param  int    $width        Video width in pixels
     * @param  int    $height       Video height in pixels
     */
    public function generateAssSubtitles(array $words, array $styleOptions, string $outputFile, int $width, int $height): void
    {
        // 1. Resolve preset, then merge user overrides on top
        $preset   = $styleOptions['preset'] ?? 'center_word';
        $defaults = $this->presetDefaults($preset);
        $style    = array_merge($defaults, $styleOptions);

        // 2. Extract resolved values
        $fontName     = (string)  ($style['fontname']       ?? 'Montserrat');
        $fontSize     = (int)     ($style['fontsize']        ?? 72);
        $bold         = (bool)    ($style['bold']            ?? true) ? -1 : 0;
        $outline      = (int)     ($style['outline']         ?? 4);
        $shadow       = (int)     ($style['shadow']          ?? 3);
        $alignment    = (int)     ($style['alignment']       ?? 5);
        $marginV      = (int)     ($style['margin_v']        ?? 0);
        $marginH      = (int)     ($style['margin_h']        ?? 30);
        $wordsPerLine = (int)     ($style['words_per_line']  ?? 4);
        $karaokeMode  = (string)  ($style['karaoke_mode']    ?? 'kf'); // 'kf' or 'k'

        // Colors – accept both hex (#RRGGBB) and ASS (&HBBGGRR) notation
        $primaryColor   = $this->toAssColor($style['primary_color']   ?? '#FFFFFF');
        $highlightColor = $this->toAssColor($style['highlight_color']  ?? '#FFD700');
        $outlineColor   = $this->toAssColor($style['outline_color']    ?? '#000000');
        $backColor      = $this->toAssAlphaColor($style['back_color']  ?? '#000000', 0x90); // 56 % opaque

        // 3. Chunk words into display lines
        $lines       = [];
        $currentLine = [];
        foreach ($words as $word) {
            $currentLine[] = $word;
            $atLimit    = count($currentLine) >= $wordsPerLine;
            $atSentence = (bool) preg_match('/[.?!]\s*$/', trim($word['word']));
            if ($atLimit || $atSentence) {
                $lines[]     = $currentLine;
                $currentLine = [];
            }
        }
        if (!empty($currentLine)) {
            $lines[] = $currentLine;
        }

        // 4. Build ASS content
        $ass  = "[Script Info]\n";
        $ass .= "ScriptType: v4.00+\n";
        $ass .= "Collisions: Normal\n";
        $ass .= "PlayResX: {$width}\n";
        $ass .= "PlayResY: {$height}\n";
        $ass .= "Timer: 100.0000\n\n";

        $ass .= "[V4+ Styles]\n";
        $ass .= "Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n";
        // BorderStyle 1 = outline + shadow (not a filled box), cleanest look on video
        $ass .= "Style: Default,{$fontName},{$fontSize},{$primaryColor},{$highlightColor},{$outlineColor},{$backColor},{$bold},0,0,0,100,100,0,0,1,{$outline},{$shadow},{$alignment},{$marginH},{$marginH},{$marginV},1\n\n";

        $ass .= "[Events]\n";
        $ass .= "Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";

        // 5. Emit one Dialogue line per display-line using karaoke tags
        foreach ($lines as $lineWords) {
            if (empty($lineWords)) continue;

            $lineStart = (float) ($lineWords[0]['start'] ?? 0);
            $lineEnd   = (float) (end($lineWords)['end'] ?? $lineStart + 2.0);

            // Build karaoke text: {\\kf<cs>}Word or {\\k<cs>}Word per word
            $karaokeText = '';
            foreach ($lineWords as $w) {
                $wStart   = (float) ($w['start'] ?? 0);
                $wEnd     = (float) ($w['end']   ?? $wStart + 0.3);
                $wDurCs   = max(1, (int) round(($wEnd - $wStart) * 100)); // centiseconds
                $wText    = str_replace(['\\', '{', '}'], ['\\\\', '\\{', '\\}'], trim((string) $w['word']));
                $karaokeText .= "{{\\{$karaokeMode}{$wDurCs}}}{$wText} ";
            }

            $startFmt = $this->formatAssTime($lineStart);
            $endFmt   = $this->formatAssTime($lineEnd);

            $ass .= "Dialogue: 0,{$startFmt},{$endFmt},Default,,0,0,0,,{$karaokeText}\n";
        }

        file_put_contents($outputFile, $ass);
    }

    /**
     * Convert a color value to ASS &H00BBGGRR format.
     * Accepts:
     *   - Hex string  '#RRGGBB' or 'RRGGBB'
     *   - ASS literal '&HBBGGRR' or '&H00BBGGRR' (passed through unchanged)
     */
    protected function toAssColor(string $color): string
    {
        $color = trim($color);

        if (str_starts_with($color, '&H')) {
            // Already in ASS format – normalise to &H00BBGGRR (8 hex digits)
            $hex = strtoupper(ltrim(substr($color, 2), '0') ?: '0');
            return '&H' . str_pad($hex, 8, '0', STR_PAD_LEFT);
        }

        // Hex #RRGGBB → ASS &H00BBGGRR
        $hex = ltrim($color, '#');
        if (strlen($hex) === 6) {
            [$r, $g, $b] = [substr($hex, 0, 2), substr($hex, 2, 2), substr($hex, 4, 2)];
            return '&H00' . strtoupper("{$b}{$g}{$r}");
        }

        return '&H00FFFFFF'; // safe fallback: white
    }

    /**
     * Same as toAssColor but embeds an alpha byte (00 = opaque, FF = transparent).
     * The BackColour field in ASS uses &HAABBGGRR.
     */
    protected function toAssAlphaColor(string $color, int $alpha = 0x00): string
    {
        $base = $this->toAssColor($color); // e.g. &H00FFFFFF
        // Replace the alpha nibbles (positions 2-3)
        $alphaByte = strtoupper(sprintf('%02X', $alpha));
        return '&H' . $alphaByte . substr($base, 4); // &HaaRRGGBB → &HaaBBGGRR
    }

    /**
     * Format seconds as ASS H:MM:SS.cc timestamp.
     */
    protected function formatAssTime(float $seconds): string
    {
        $hours     = (int) floor($seconds / 3600);
        $minutes   = (int) floor(($seconds % 3600) / 60);
        $secs      = (int) floor($seconds % 60);
        $centisecs = (int) round(fmod($seconds, 1) * 100);

        return sprintf('%d:%02d:%02d.%02d', $hours, $minutes, $secs, $centisecs);
    }
}
