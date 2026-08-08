<?php

namespace PhpVideoAutomator\Engines;

use Exception;
use PhpVideoAutomator\Exceptions\VideoAutomatorException;
use PhpVideoAutomator\Services\AiImageService;
use PhpVideoAutomator\Services\AiTextService;
use PhpVideoAutomator\Services\AiVoiceService;
use PhpVideoAutomator\Services\AssSubtitleService;
use PhpVideoAutomator\Services\InternetArchiveService;
use PhpVideoAutomator\Services\PexelsService;
use PhpVideoAutomator\Services\PixabayService;
use PhpVideoAutomator\Services\WikimediaService;
use PhpVideoAutomator\Traits\HandlesCaptions;
use Symfony\Component\Process\Process;

class ImageToVideoEngine
{
    use HandlesCaptions;

    protected array $config;
    protected string $script = '';
    protected array $chunks = [];
    protected array $captionChunks = [];
    protected array $images = [];
    protected string $animation = 'none';
    protected ?string $audioPath = null;
    protected int $width = 1080;
    protected int $height = 1920;
    protected float $imageDuration = 4.0;
    protected ?int $targetDuration = null;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function setScript(string $script): self
    {
        $this->script = $script;
        $this->chunks = $this->splitIntoChunks($script);
        return $this;
    }

    public function setCaptions(string $captions): self
    {
        $this->captionChunks = $this->splitIntoChunks($captions);
        return $this;
    }

    public function setTargetDuration(int $seconds): self
    {
        $this->targetDuration = max(1, $seconds);
        return $this;
    }

    public function generateImages(string $apiKey = '', string $provider = 'openai'): self
    {
        $apiKey = $apiKey ?: ($this->config['ai_image_api_key'] ?? '');

        if (empty($apiKey)) {
            return $this->fetchStockImages();
        }

        $service = new AiImageService($apiKey, $provider);

        $size = '1024x1024';
        if ($this->width < $this->height) $size = '1024x1536';
        if ($this->width > $this->height) $size = '1536x1024';

        foreach ($this->chunks as $index => $chunk) {
            $prompt = "Create a high-quality, detailed image exactly matching this description: '" . trim($chunk) . "'. Adhere to any specific art style or medium requested. If none is specified, default to a photorealistic cinematic style.";
            $this->images[$index] = $service->generateImage($prompt, $size);
        }

        return $this;
    }

    public function fetchStockImages(string $provider = 'auto', string $apiKey = ''): self
    {
        $aiKey = $this->config['ai_image_api_key'] ?? '';
        $textService = !empty($aiKey) ? new AiTextService($aiKey) : null;

        $providersToTry = $provider === 'auto' ? ['pixabay', 'pexels', 'wikimedia', 'archive'] : [$provider];
        $usedUrls = [];

        foreach ($this->chunks as $index => $chunk) {
            $query = trim($chunk);
            if ($textService) {
                try {
                    $query = $textService->extractStockVideoKeywords($chunk);
                } catch (Exception $e) {
                    error_log('[PhpVideoAutomator] AI keyword extraction failed for chunk ' . $index . ': ' . $e->getMessage());
                }
            }

            if (strlen($query) > 100) {
                $query = substr($query, 0, 100);
            }

            $imageUrl = null;

            foreach ($providersToTry as $p) {
                $imageUrl = $this->searchProviderForImage($p, $query, true, $textService, $chunk, $usedUrls);
                if ($imageUrl) {
                    break;
                }
            }

            if (!$imageUrl) {
                $fallbackQuery = 'scenery background abstract';
                foreach ($providersToTry as $p) {
                    $imageUrl = $this->searchProviderForImage($p, $fallbackQuery, false, null, '', $usedUrls);
                    if ($imageUrl) break;
                }
            }

            if (!$imageUrl) {
                throw new VideoAutomatorException('Could not fetch any stock image for the prompt.');
            }

            $this->images[$index] = $imageUrl;
            $usedUrls[] = $imageUrl;
        }

        return $this;
    }

    private function searchProviderForImage(string $provider, string $query, bool $randomize = true, ?AiTextService $textService = null, string $scene = '', array &$usedUrls = []): ?string
    {
        $key = $this->config["{$provider}_api_key"] ?? '';
        if (empty($key) && in_array($provider, ['pixabay', 'pexels'])) {
            return null;
        }

        try {
            $results = [];
            if ($provider === 'pixabay') {
                $service = new PixabayService($key);
                $results = $service->searchImages($query, 10);
            } elseif ($provider === 'pexels') {
                $service = new PexelsService($key);
                $results = $service->searchImages($query, 10);
            } elseif ($provider === 'wikimedia') {
                $service = new WikimediaService();
                $results = $service->searchImages($query, 10);
            } elseif ($provider === 'archive') {
                $service = new InternetArchiveService();
                $results = $service->searchImages($query, 10);
            }

            if (empty($results)) {
                return null;
            }

            $validItems = [];
            foreach ($results as $item) {
                $url = null;
                if ($provider === 'pixabay') {
                    $url = $item['largeImageURL'] ?? ($item['webformatURL'] ?? null);
                } elseif ($provider === 'pexels') {
                    $url = $item['src']['large2x'] ?? ($item['src']['large'] ?? null);
                } elseif ($provider === 'wikimedia' || $provider === 'archive') {
                    $url = $item['url'] ?? null;
                }
                if ($url && !in_array($url, $usedUrls)) {
                    $item['_final_url'] = $url;
                    $validItems[] = $item;
                }
            }

            if (empty($validItems)) {
                return null;
            }

            $selectedIndex = 0;
            if ($textService && !empty($scene)) {
                $options = [];
                foreach (array_slice($validItems, 0, 10) as $i => $item) {
                    $desc = '';
                    if ($provider === 'pixabay') {
                        $desc = $item['tags'] ?? '';
                    } elseif ($provider === 'pexels') {
                        $path = parse_url($item['url'] ?? '', PHP_URL_PATH) ?? '';
                        $desc = trim(str_replace('-', ' ', preg_replace('/-\d+\/?$/', '', basename($path))));
                    } elseif ($provider === 'wikimedia' || $provider === 'archive') {
                        $desc = $item['title'] ?? '';
                    }
                    $options[$i] = $desc;
                }
                try {
                    $selectedIndex = $textService->selectBestMediaIndex($scene, $options);
                } catch (Exception $e) {
                    $selectedIndex = 0;
                }
            } elseif ($randomize) {
                $selectedIndex = array_rand(array_slice($validItems, 0, min(3, count($validItems))));
            }

            $result = $validItems[$selectedIndex] ?? $validItems[0];
            return $result['_final_url'] ?? null;
        } catch (Exception $e) {
            error_log('[PhpVideoAutomator] Stock image provider "' . $provider . '" failed: ' . $e->getMessage());
        }

        return null;
    }

    public function addAnimation(string $type = 'zoompan'): self
    {
        $this->animation = $type;
        return $this;
    }

    public function withAudio(string $audioPath): self
    {
        $this->audioPath = $audioPath;
        return $this;
    }

    public function setDimensions(int $width, int $height): self
    {
        $this->width = $width;
        $this->height = $height;
        return $this;
    }

    public function setImageDuration(float $seconds): self
    {
        $this->imageDuration = max(0.5, $seconds);
        return $this;
    }

    public function export(string $outputPath): bool
    {
        if (empty($this->images)) {
            throw new VideoAutomatorException('No images to process. Call generateImages() first.');
        }

        $outDir = dirname($outputPath);
        if (!is_dir($outDir)) {
            @mkdir($outDir, 0777, true);
        }

        $tempDir = sys_get_temp_dir() . '/video_automator_img_' . uniqid('', true);
        if (!mkdir($tempDir, 0777, true) && !is_dir($tempDir)) {
            throw new VideoAutomatorException(sprintf('Temp directory "%s" could not be created.', $tempDir));
        }

        try {
            $ffmpegPath = $this->config['ffmpeg_path'] ?? 'ffmpeg';
            $imageCount = count($this->images);

            $baseDuration = $this->targetDuration !== null
                ? (float) $this->targetDuration
                : (float) ($imageCount * $this->imageDuration);

            $finalVideoDuration = $baseDuration;
            $wordTimestamps = [];
            $ttsAudioPath = '';

            if (!empty($this->voiceOptions)) {
                $captionsText = implode(' ', $this->captionChunks ?: $this->chunks);
                $voiceService = new AiVoiceService();
                $ttsAudioPath = $tempDir . '/tts.mp3';

                $voiceModel = !empty($this->voiceOptions['voiceId']) ? $this->voiceOptions['voiceId'] : ($this->voiceOptions['model'] ?? '');
                $voiceSpeed = (float) ($this->voiceOptions['speed'] ?? 1.0);

                $wordTimestamps = $voiceService->generateVoiceoverWithTimestamps(
                    $captionsText,
                    $this->voiceOptions['provider'],
                    $voiceModel,
                    $this->voiceOptions['apiKey'],
                    $ttsAudioPath,
                    $voiceSpeed
                );

                if (file_exists($ttsAudioPath)) {
                    $ttsDuration = $this->probeDuration($ffmpegPath, $ttsAudioPath);
                    if ($ttsDuration > 0) {
                        if ($ttsDuration > $finalVideoDuration) {
                            $finalVideoDuration = $ttsDuration;
                        }
                    }
                }
            }

            $perImageDuration = round($finalVideoDuration / $imageCount, 4);
            $durationStr = number_format($finalVideoDuration, 4, '.', '');

            $clips = [];
            foreach ($this->images as $index => $imageUrl) {
                $imagePath = $tempDir . "/img_{$index}.jpg";
                if (!@copy($imageUrl, $imagePath)) {
                    throw new VideoAutomatorException('Failed to download generated image for scene ' . $index . '.');
                }

                $captionText = !empty($this->captionChunks) ? ($this->captionChunks[$index] ?? '') : ($this->chunks[$index] ?? '');
                $text = ($this->addCaptions && empty($this->voiceOptions)) ? $captionText : '';

                $clipPath = $tempDir . "/clip_{$index}.mp4";
                $this->createClipFromImage($imagePath, $clipPath, $text, $perImageDuration);
                $clips[] = $clipPath;
            }

            $listPath = $tempDir . '/list.txt';
            file_put_contents($listPath, implode("\n", array_map(fn($c) => "file '{$c}'", $clips)));

            $rawOutput = (!empty($ttsAudioPath) || $this->audioPath) ? $tempDir . '/raw_output.mp4' : $outputPath;

            $concatCmd = [
                $ffmpegPath, '-y', '-f', 'concat', '-safe', '0', '-i', $listPath,
                '-c', 'copy', $rawOutput,
            ];
            $this->runProcess($concatCmd, 'Concat');

            if (!empty($ttsAudioPath)) {
                $this->burnSubtitlesAndMergeAudio($ffmpegPath, $rawOutput, $ttsAudioPath, $wordTimestamps, $outputPath, $durationStr, $tempDir);
            } elseif ($this->audioPath && file_exists($this->audioPath)) {
                $audioCmd = [
                    $ffmpegPath, '-y',
                    '-i', $rawOutput,
                    '-stream_loop', '-1', '-i', $this->audioPath,
                    '-map', '0:v:0', '-map', '1:a:0',
                    '-c:v', 'copy', '-c:a', 'aac', '-b:a', '128k',
                    '-shortest', '-t', $durationStr,
                    $outputPath,
                ];
                $this->runProcess($audioCmd, 'Audio merge');
            }

            return true;
        } finally {
            $this->cleanup($tempDir);
        }
    }


    protected function burnSubtitlesAndMergeAudio(string $ffmpegPath, string $rawOutput, string $ttsAudioPath, array $wordTimestamps, string $outputPath, string $durationStr, string $tempDir): void
    {
        $mixedAudioPath = $ttsAudioPath;

        if ($this->audioPath && file_exists($this->audioPath)) {
            $mixedAudioPath = $tempDir . '/mixed.mp3';
            $mixCmd = [
                $ffmpegPath, '-y',
                '-i', $ttsAudioPath,
                '-stream_loop', '-1', '-i', $this->audioPath,
                '-filter_complex', '[0:a]volume=1.0,apad[a1];[1:a]volume=0.2[a2];[a1][a2]amix=inputs=2:duration=longest',
                '-t', $durationStr,
                $mixedAudioPath,
            ];
            $proc = new Process($mixCmd);
            $proc->setTimeout(300);
            $proc->run();
            if (!$proc->isSuccessful()) {
                $mixedAudioPath = $ttsAudioPath;
            }
        }

        $assFile = $tempDir . '/subs.ass';
        $assService = new AssSubtitleService();
        $assService->generateAssSubtitles($wordTimestamps, $this->captionStyleOptions, $assFile, $this->width, $this->height);

        $fontPath = $this->config['font_path'] ?? '';
        $assFilter = is_dir(dirname($fontPath)) && !empty($fontPath)
            ? sprintf("ass='%s':fontsdir='%s'", str_replace("'", "\\'", $assFile), str_replace("'", "\\'", dirname($fontPath)))
            : sprintf("ass='%s'", str_replace("'", "\\'", $assFile));

        $threads = max(1, (int) shell_exec('nproc 2>/dev/null') - 1 ?: 2);

        $burnCmd = [
            $ffmpegPath, '-y',
            '-stream_loop', '-1', '-i', $rawOutput,
            '-i', $mixedAudioPath,
            '-filter_complex', "[0:v]{$assFilter}[v]",
            '-map', '[v]', '-map', '1:a',
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23',
            '-threads', (string) $threads,
            '-c:a', 'aac', '-b:a', '128k',
            '-t', $durationStr,
            $outputPath,
        ];
        $this->runProcess($burnCmd, 'Subtitle burn');
    }

    protected function probeDuration(string $ffmpegPath, string $filePath): float
    {
        $ffprobePath = str_replace('ffmpeg', 'ffprobe', $ffmpegPath);
        $cmd = sprintf(
            '%s -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s 2>/dev/null',
            escapeshellarg($ffprobePath),
            escapeshellarg($filePath)
        );
        $output = trim((string) shell_exec($cmd));
        return is_numeric($output) ? (float) $output : 0.0;
    }

    protected function rescaleTimestamps(array $wordTimestamps, float $ratio): array
    {
        return array_map(static function (array $word) use ($ratio): array {
            $word['start'] = round($word['start'] / $ratio, 4);
            $word['end'] = round($word['end'] / $ratio, 4);
            return $word;
        }, $wordTimestamps);
    }

    protected function splitIntoChunks(string $script): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+|\n/', $script, -1, PREG_SPLIT_NO_EMPTY);

        if (count($sentences) === 1 && strlen($script) > 80) {
            $aiKey = $this->config['ai_image_api_key'] ?? '';
            if (!empty($aiKey)) {
                try {
                    $textService = new AiTextService($aiKey);
                    $formatted = $textService->smartFormatScript($script, 3);
                    if (!empty($formatted)) {
                        $sentences = preg_split('/(?<=[.!?])\s+|\n/', $formatted, -1, PREG_SPLIT_NO_EMPTY);
                    }
                } catch (Exception $e) {
                    error_log('[PhpVideoAutomator] Script formatting failed: ' . $e->getMessage());
                }
            }
        }

        return array_values(array_filter(array_map('trim', $sentences)));
    }

    protected function createClipFromImage(string $imagePath, string $outputPath, string $text, float $duration): void
    {
        $ffmpegPath = $this->config['ffmpeg_path'] ?? 'ffmpeg';
        $fps = 25;
        $frames = (int) round($duration * $fps);
        $frames = max(1, $frames);
        $durationStr = number_format($duration, 4, '.', '');

        $threads = max(1, (int) shell_exec('nproc 2>/dev/null') - 1 ?: 2);

        if ($this->animation === 'zoompan' || $this->animation === 'ken-burns') {
            $w2 = $this->width * 2;
            $h2 = $this->height * 2;

            $effects = [
                "z='min(zoom+0.0008,1.5)':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)'",
                "z='if(eq(on,1),1.15,zoom-0.0008)':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)'",
                "z='1.08':x='(on/{$frames})*(iw-(iw/zoom))':y='ih/2-(ih/zoom/2)'",
                "z='1.08':x='(1-(on/{$frames}))*(iw-(iw/zoom))':y='ih/2-(ih/zoom/2)'",
                "z='1.08':x='iw/2-(iw/zoom/2)':y='(on/{$frames})*(ih-(ih/zoom))'",
            ];
            $effect = $effects[array_rand($effects)];

            $filter = "[0:v]scale={$w2}:{$h2}:force_original_aspect_ratio=increase,crop={$w2}:{$h2},setsar=1,zoompan={$effect}:d={$frames}:s={$this->width}x{$this->height}:fps={$fps}";
        } else {
            $filter = "[0:v]scale={$this->width}:{$this->height}:force_original_aspect_ratio=increase,crop={$this->width}:{$this->height},setsar=1,fps={$fps}";
        }

        if ($text !== '') {
            $limit = $this->getCaptionWordwrapLimit($this->width);
            $text = wordwrap($text, $limit, "\n");
            $txtPath = dirname($outputPath) . '/' . basename($outputPath, '.mp4') . '.txt';
            file_put_contents($txtPath, $text);
            $safeTxtPath = str_replace(['\\', ':'], ['/', '\\:'], $txtPath);
            $filter .= ',' . $this->getCaptionFilter($safeTxtPath, $this->width, $this->height);
        }

        $command = [
            $ffmpegPath, '-y',
            '-loop', '1', '-i', $imagePath,
            '-f', 'lavfi', '-i', 'anullsrc=channel_layout=stereo:sample_rate=44100',
            '-vf', $filter,
            '-map', '0:v:0', '-map', '1:a:0',
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23',
            '-threads', (string) $threads,
            '-c:a', 'aac', '-b:a', '64k',
            '-t', $durationStr,
            '-pix_fmt', 'yuv420p',
            $outputPath,
        ];

        $this->runProcess($command, 'Image clip creation');
    }

    protected function runProcess(array $command, string $label): void
    {
        $process = new Process($command);
        $process->setTimeout(3600);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new VideoAutomatorException("[{$label}] FFmpeg error: " . $process->getErrorOutput());
        }
    }

    protected function cleanup(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (array_diff((array) scandir($dir), ['.', '..']) as $file) {
            @unlink("{$dir}/{$file}");
        }
        @rmdir($dir);
    }
}