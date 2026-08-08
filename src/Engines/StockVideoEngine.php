<?php

namespace PhpVideoAutomator\Engines;

use PhpVideoAutomator\Exceptions\VideoAutomatorException;
use PhpVideoAutomator\Services\AiTextService;
use PhpVideoAutomator\Services\AiVoiceService;
use PhpVideoAutomator\Services\AssSubtitleService;
use PhpVideoAutomator\Services\InternetArchiveService;
use PhpVideoAutomator\Services\PexelsService;
use PhpVideoAutomator\Services\PixabayService;
use PhpVideoAutomator\Services\WikimediaService;
use PhpVideoAutomator\Traits\HandlesCaptions;
use Symfony\Component\Process\Process;
use Throwable;

class StockVideoEngine
{
    use HandlesCaptions;

    protected array $config;
    protected string $script = '';
    protected array $chunks = [];
    protected array $captionChunks = [];
    protected array $videos = [];
    protected ?string $audioPath = null;
    protected int $width = 1080;
    protected int $height = 1920;
    protected float $maxClipDuration = 5.0;
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

    public function setDimensions(int $width, int $height): self
    {
        $this->width = $width;
        $this->height = $height;
        return $this;
    }

    public function setMaxClipDuration(float $seconds): self
    {
        $this->maxClipDuration = max(1.0, $seconds);
        return $this;
    }

    public function withAudio(string $audioPath): self
    {
        $this->audioPath = $audioPath;
        return $this;
    }

    public function addTransitions(string $type = 'fade'): self
    {
        return $this;
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
                } catch (Throwable $e) {
                    error_log('[PhpVideoAutomator] Script formatting failed: ' . $e->getMessage());
                }
            }
        }

        return array_values(array_filter(array_map('trim', $sentences)));
    }

    public function fetchStockVideos(string $provider = 'auto', string $apiKey = '', array $options = []): self
    {
        $aiKey = $this->config['ai_image_api_key'] ?? '';
        $textService = !empty($aiKey) ? new AiTextService($aiKey) : null;

        $count = max(1, (int) ($options['count'] ?? 3));
        $randomize = (bool) ($options['randomize'] ?? true);

        $chunksToProcess = !empty($this->chunks) ? $this->chunks : [$this->script];
        $numChunks = count($chunksToProcess);

        $videosPerChunk = array_fill(0, $numChunks, 0);
        for ($i = 0, $remaining = $count; $remaining > 0; $remaining--, $i = ($i + 1) % $numChunks) {
            $videosPerChunk[$i]++;
        }

        $providersToTry = $provider === 'auto' ? ['pixabay', 'pexels', 'wikimedia', 'archive'] : [$provider];
        $usedUrls = [];
        $fallbackPool = $this->buildFallbackPool($apiKey, $providersToTry);

        foreach ($chunksToProcess as $index => $chunk) {
            $videosNeeded = $videosPerChunk[$index] ?? 0;
            if ($videosNeeded <= 0) continue;

            $query = $chunk;
            if ($textService) {
                try {
                    $query = $textService->extractStockVideoKeywords($chunk);
                } catch (Throwable $e) {
                    error_log('[PhpVideoAutomator] AI keyword extraction failed: ' . $e->getMessage());
                }
            }
            if (strlen($query) > 100) {
                $query = substr($query, 0, 100);
            }

            [$results, $activeProvider] = $this->fetchFromProviders($providersToTry, $query, $apiKey);

            $validUrls = $this->extractValidUrls($results, $activeProvider, $chunk, $textService, $randomize, $usedUrls);

            for ($j = 0; $videosNeeded > 0; $videosNeeded--, $j++) {
                if (isset($validUrls[$j])) {
                    $selectedUrl = $validUrls[$j];
                } else {
                    $selectedUrl = $this->shiftFromFallback($fallbackPool, $usedUrls)
                        ?? ($validUrls[$j % max(1, count($validUrls))] ?? '');
                }
                if ($selectedUrl) {
                    $this->videos[] = $selectedUrl;
                    $usedUrls[] = $selectedUrl;
                }
            }
        }

        if (empty($this->videos)) {
            throw new VideoAutomatorException('Render failed. The scene brief is too complex for this engine. Please try simplifying it.');
        }

        return $this;
    }

    private function buildFallbackPool(string $apiKey, array $providersToTry): array
    {
        $pool = [];
        try {
            $pixKey = $apiKey ?: ($this->config['pixabay_api_key'] ?? '');
            if (!empty($pixKey)) {
                $res = (new PixabayService($pixKey))->searchVideos('background abstract nature', 100);
                foreach ($res as $video) {
                    $u = $video['videos']['large']['url'] ?? ($video['videos']['medium']['url'] ?? '');
                    if ($u) $pool[] = $u;
                }
            }

            if (empty($pool)) {
                $pexKey = $apiKey ?: ($this->config['pexels_api_key'] ?? '');
                if (!empty($pexKey)) {
                    $res = (new PexelsService($pexKey))->searchVideos('background abstract nature', 80);
                    foreach ($res as $video) {
                        foreach ($video['video_files'] ?? [] as $f) {
                            if (in_array($f['quality'] ?? '', ['hd', 'sd'])) {
                                $pool[] = $f['link'];
                                break;
                            }
                        }
                    }
                }
            }

            if (!empty($pool)) shuffle($pool);
        } catch (Throwable $e) {}

        return $pool;
    }

    private function fetchFromProviders(array $providers, string $query, string $apiKey): array
    {
        foreach ($providers as $p) {
            $key = $apiKey ?: ($this->config[$p . '_api_key'] ?? '');
            try {
                $results = match ($p) {
                    'pixabay' => !empty($key) ? (new PixabayService($key))->searchVideos($query, 40) : [],
                    'pexels' => !empty($key) ? (new PexelsService($key))->searchVideos($query, 40) : [],
                    'wikimedia' => (new WikimediaService())->searchVideos($query, 15),
                    'archive' => (new InternetArchiveService())->searchVideos($query, 15),
                    default => [],
                };
                if (!empty($results)) {
                    return [$results, $p];
                }
            } catch (Throwable $e) {
                continue;
            }
        }
        return [[], ''];
    }

    private function extractValidUrls(array $results, string $activeProvider, string $chunk, ?AiTextService $textService, bool $randomize, array $usedUrls): array
    {
        if (empty($results)) return [];

        if ($textService && !empty($chunk) && !empty($activeProvider)) {
            $optionsDesc = [];
            foreach (array_slice($results, 0, 10) as $idx => $item) {
                $desc = match ($activeProvider) {
                    'pixabay' => $item['tags'] ?? '',
                    'pexels' => trim(str_replace('-', ' ', preg_replace('/-\d+\/?$/', '', basename(parse_url($item['url'] ?? '', PHP_URL_PATH) ?? '')))),
                    default => $item['title'] ?? '',
                };
                $optionsDesc[$idx] = $desc;
            }
            try {
                $bestIndex = $textService->selectBestMediaIndex($chunk, $optionsDesc);
                if (isset($results[$bestIndex])) {
                    $best = $results[$bestIndex];
                    unset($results[$bestIndex]);
                    array_unshift($results, $best);
                }
            } catch (Throwable $e) {}
        } elseif ($randomize) {
            shuffle($results);
        }

        $validUrls = [];
        foreach ($results as $video) {
            $url = match ($activeProvider) {
                'pixabay' => $video['videos']['large']['url'] ?? $video['videos']['medium']['url'] ?? $video['videos']['small']['url'] ?? $video['videos']['tiny']['url'] ?? '',
                'pexels' => $this->extractPexelsUrl($video),
                default => $video['url'] ?? '',
            };
            if ($url && !in_array($url, $usedUrls)) {
                $validUrls[] = $url;
            }
        }
        return $validUrls;
    }

    private function extractPexelsUrl(array $video): string
    {
        $files = $video['video_files'] ?? [];
        foreach ($files as $file) {
            if (in_array($file['quality'] ?? '', ['uhd', 'hd'])) return $file['link'];
        }
        foreach ($files as $file) {
            if (($file['quality'] ?? '') === 'sd') return $file['link'];
        }
        return $files[0]['link'] ?? '';
    }

    private function shiftFromFallback(array &$pool, array $usedUrls): ?string
    {
        while (!empty($pool)) {
            $url = array_shift($pool);
            if (!in_array($url, $usedUrls)) return $url;
        }
        return null;
    }

    public function export(string $outputPath): bool
    {
        if (empty($this->videos)) {
            throw new VideoAutomatorException('No videos to process. Call fetchStockVideos() first.');
        }

        $outDir = dirname($outputPath);
        if (!is_dir($outDir)) {
            @mkdir($outDir, 0777, true);
        }

        $tempDir = sys_get_temp_dir() . '/video_automator_stock_' . uniqid('', true);
        if (!mkdir($tempDir, 0777, true) && !is_dir($tempDir)) {
            throw new VideoAutomatorException(sprintf('Temp directory "%s" could not be created.', $tempDir));
        }

        try {
            $ffmpegPath = $this->config['ffmpeg_path'] ?? 'ffmpeg';
            $videoCount = count($this->videos);

            $baseDuration = $this->targetDuration !== null
                ? (float) $this->targetDuration
                : (float) ($videoCount * $this->maxClipDuration);

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
                        $ratio = $ttsDuration / $baseDuration;
                        
                        // Limit clamping to a natural range: max 15% speedup, max 10% slowdown
                        $clampedRatio = min(1.15, max(0.90, $ratio));
                        
                        if (abs($clampedRatio - 1.0) > 0.02) {
                            $ttsAudioPath = $this->applyNaturalAtempo($ffmpegPath, $ttsAudioPath, $tempDir, $clampedRatio, $wordTimestamps);
                            $ttsDuration = $ttsDuration / $clampedRatio;
                        }
                        
                        // If voiceover is still longer than requested duration, extend the video gracefully
                        if ($ttsDuration > $finalVideoDuration) {
                            $finalVideoDuration = $ttsDuration;
                        }
                    }
                }
            }

            $perClipDuration = round($finalVideoDuration / $videoCount, 4);
            $durationStr = number_format($finalVideoDuration, 4, '.', '');

            $clips = [];
            foreach ($this->videos as $index => $videoUrl) {
                $rawPath = $tempDir . "/raw_{$index}.mp4";
                if (!@copy($videoUrl, $rawPath)) {
                    throw new VideoAutomatorException('Failed to download video from: ' . $videoUrl);
                }

                $captionText = !empty($this->captionChunks) ? ($this->captionChunks[$index] ?? '') : ($this->chunks[$index] ?? '');
                $text = ($this->addCaptions && empty($this->voiceOptions)) ? $captionText : '';

                $clipPath = $tempDir . "/clip_{$index}.mp4";
                $this->standardizeClip($rawPath, $clipPath, $text, $perClipDuration);
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

    protected function standardizeClip(string $inputPath, string $outputPath, string $text, float $clipDuration): void
    {
        $ffmpegPath = $this->config['ffmpeg_path'] ?? 'ffmpeg';
        $durationStr = number_format($clipDuration, 4, '.', '');
        $threads = max(1, (int) shell_exec('nproc 2>/dev/null') - 1 ?: 2);

        $filter = "scale={$this->width}:{$this->height}:force_original_aspect_ratio=increase,crop={$this->width}:{$this->height},setsar=1,fps=25";

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
            '-ss', '0',
            '-stream_loop', '-1', '-i', $inputPath,
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

        $this->runProcess($command, 'Standardize clip');
    }

    protected function applyNaturalAtempo(string $ffmpegPath, string $ttsAudioPath, string $tempDir, float $ratio, array &$wordTimestamps): string
    {
        $clampedPath = $tempDir . '/tts_clamped_' . uniqid() . '.mp3';
        $filter = sprintf('atempo=%.4f', $ratio);

        $clampCmd = [
            $ffmpegPath, '-y', '-i', $ttsAudioPath,
            '-filter:a', $filter,
            $clampedPath,
        ];

        $proc = new Process($clampCmd);
        $proc->setTimeout(120);
        $proc->run();

        if ($proc->isSuccessful() && file_exists($clampedPath)) {
            @unlink($ttsAudioPath);
            $wordTimestamps = $this->rescaleTimestamps($wordTimestamps, $ratio);
            return $clampedPath;
        }

        return $ttsAudioPath;
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