<?php

namespace PhpVideoAutomator\Traits;

trait HandlesCaptions
{
    protected bool $addCaptions = false;
    protected array $captionStyleOptions = [];
    protected array $voiceOptions = [];

    public function withCaptions(bool $enable = true, array $styleOptions = []): self
    {
        $this->addCaptions = $enable;
        $this->captionStyleOptions = $styleOptions;
        return $this;
    }

    public function withPremiumVoice(string $provider, string $model, string $apiKey, string $voiceId = '', float $speed = 1.0): self
    {
        $this->voiceOptions = [
            'provider' => $provider,
            'model' => $model,
            'apiKey' => $apiKey,
            'voiceId' => $voiceId,
            'speed' => $speed,
        ];
        return $this;
    }

    protected function getCaptionWordwrapLimit(int $width): int
    {
        $preset = $this->captionStyleOptions['preset'] ?? 'classic';
        
        $presets = [
            'classic' => 36,
            'modern_middle' => 54,
            'elegant_bottom' => 45,
            'bold_top' => 60,
            'cinematic' => 40
        ];
        
        $fontsize = $this->captionStyleOptions['fontsize'] ?? ($presets[$preset] ?? 36);
        $fontsize = (int)$fontsize > 0 ? (int)$fontsize : 36;
        
        $limit = floor(($width * 0.9) / ($fontsize * 0.55));
        
        return max(15, (int)$limit);
    }

    protected function getCaptionFilter(string $safeTxtPath, int $width, int $height): string
    {
        $preset = $this->captionStyleOptions['preset'] ?? 'classic';
        
        // Define preset templates
        $presets = [
            'classic' => [
                'fontcolor' => 'white',
                'fontsize' => 36,
                'box' => 1,
                'boxcolor' => 'black@0.45',
                'boxborderw' => 24,
                'x' => '(w-text_w)/2',
                'y' => 'h-text_h-180',
                'line_spacing' => 12,
                'shadowcolor' => '',
                'shadowx' => 0,
                'shadowy' => 0,
            ],
            'modern_middle' => [
                'fontcolor' => 'white',
                'fontsize' => 54,
                'box' => 1,
                'boxcolor' => 'black@0.6',
                'boxborderw' => 30,
                'x' => '(w-text_w)/2',
                'y' => '(h-text_h)/2',
                'line_spacing' => 15,
                'shadowcolor' => '',
                'shadowx' => 0,
                'shadowy' => 0,
            ],
            'elegant_bottom' => [
                'fontcolor' => 'gold',
                'fontsize' => 45,
                'box' => 0,
                'boxcolor' => '',
                'boxborderw' => 0,
                'x' => '(w-text_w)/2',
                'y' => 'h-text_h-200',
                'line_spacing' => 15,
                'shadowcolor' => 'black@0.8',
                'shadowx' => 4,
                'shadowy' => 4,
            ],
            'bold_top' => [
                'fontcolor' => 'yellow',
                'fontsize' => 60,
                'box' => 1,
                'boxcolor' => 'red@0.8',
                'boxborderw' => 20,
                'x' => '(w-text_w)/2',
                'y' => '150',
                'line_spacing' => 10,
                'shadowcolor' => 'black',
                'shadowx' => 2,
                'shadowy' => 2,
            ],
            'cinematic' => [
                'fontcolor' => 'white',
                'fontsize' => 40,
                'box' => 0,
                'boxcolor' => '',
                'boxborderw' => 0,
                'x' => '(w-text_w)/2',
                'y' => 'h-text_h-100',
                'line_spacing' => 20,
                'shadowcolor' => 'black@0.9',
                'shadowx' => 3,
                'shadowy' => 3,
            ]
        ];

        // Merge defaults, preset, and custom overrides
        $baseStyle = $presets['classic'];
        $presetStyle = $presets[$preset] ?? $baseStyle;
        $style = array_merge($baseStyle, $presetStyle, $this->captionStyleOptions);

        $fontcolor = $style['primary_color'] ?? ($style['fontcolor'] ?? 'white');
        if (str_starts_with($fontcolor, '#')) {
            $fontcolor = ltrim($fontcolor, '#');
        }
        $fontsize = $style['fontsize'] ?? 36;
        $box = $style['box'] ?? 1;
        $boxcolor = $style['boxcolor'] ?? 'black@0.45';
        $boxborderw = $style['boxborderw'] ?? 24;
        
        $x = $style['x'] ?? '(w-text_w)/2';
        $y = $style['y'] ?? 'h-text_h-180';
        
        // Alignment overriding Y
        if (isset($this->captionStyleOptions['alignment'])) {
            $alignment = (int) $this->captionStyleOptions['alignment'];
            if ($alignment === 5) {
                $y = '(h-text_h)/2';
            } elseif ($alignment === 8) {
                $y = '150';
            } elseif ($alignment === 2) {
                $y = 'h-text_h-180';
            }
        }
        
        $line_spacing = $style['line_spacing'] ?? 12;
        $shadowcolor = $style['shadowcolor'] ?? '';
        $shadowx = $style['shadowx'] ?? 0;
        $shadowy = $style['shadowy'] ?? 0;

        $filterParams = [
            "textfile='{$safeTxtPath}'",
            "fontcolor={$fontcolor}",
            "fontsize={$fontsize}",
            "x={$x}",
            "y={$y}",
            "line_spacing={$line_spacing}",
        ];
        
        if (!empty($style['fontname'])) {
            $fontNameStr = str_replace(['\\', ':', "'"], ['/', '\\:', "\\'"], $style['fontname']);
            $filterParams[] = "font='{$fontNameStr}'";
        }

        if ($box) {
            $filterParams[] = "box=1";
            $filterParams[] = "boxcolor={$boxcolor}";
            $filterParams[] = "boxborderw={$boxborderw}";
        }

        if ($shadowcolor) {
            $filterParams[] = "shadowcolor={$shadowcolor}";
            $filterParams[] = "shadowx={$shadowx}";
            $filterParams[] = "shadowy={$shadowy}";
        }

        $filterParams = array_filter($filterParams);

        return "drawtext=" . implode(':', $filterParams);
    }
}
