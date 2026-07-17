<?php

namespace PhpVideoAutomator\Engines;

use PhpVideoAutomator\Exceptions\VideoAutomatorException;
use PhpVideoAutomator\Services\PixabayService;
use PhpVideoAutomator\Services\PexelsService;
use PhpVideoAutomator\Services\WikimediaService;
use PhpVideoAutomator\Services\InternetArchiveService;
use PhpVideoAutomator\Services\AiTextService;
use PhpVideoAutomator\Services\AiVoiceService;
use PhpVideoAutomator\Services\AssSubtitleService;
use Symfony\Component\Process\Process;
use Throwable;
use PhpVideoAutomator\Traits\HandlesCaptions;

class StockVideoEngine
{
    use HandlesCaptions;

    protected array $config;
    protected string $script = '';
    protected array $videos = [];
    protected ?string $audioPath = null;
    protected int $width = 1080;
    protected int $height = 1920;
    protected float $maxClipDuration = 5.0;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    protected array $chunks = [];
    protected array $captionChunks = [];

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

    protected function splitIntoChunks(string $script): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+|\n/', $script, -1, PREG_SPLIT_NO_EMPTY);
        
        if (count($sentences) === 1 && strlen($script) > 80) {
            $aiKey = $this->config['ai_image_api_key'] ?? '';
            if (!empty($aiKey)) {
                $textService = new AiTextService($aiKey);
                $formatted = $textService->smartFormatScript($script, 3);
                if (!empty($formatted)) {
                    $sentences = preg_split('/(?<=[.!?])\s+|\n/', $formatted, -1, PREG_SPLIT_NO_EMPTY);
                }
            }
        }

        return array_values(array_filter(array_map('trim', $sentences)));
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

    public function setMaxClipDuration(float $seconds): self
    {
        $this->maxClipDuration = $seconds;
        return $this;
    }

    public function fetchStockVideos(string $provider = 'auto', string $apiKey = '', array $options = []): self
    {
        $aiKey = $this->config['ai_image_api_key'] ?? '';
        $textService = !empty($aiKey) ? new AiTextService($aiKey) : null;

        $count = $options['count'] ?? 3;
        $randomize = $options['randomize'] ?? true;
        
        $chunksToProcess = $this->chunks;
        if (empty($chunksToProcess)) {
            $chunksToProcess = [$this->script];
        }

        $numChunks = count($chunksToProcess);
        $videosPerChunk = array_fill(0, $numChunks, 0);
        $remaining = $count;
        
        $i = 0;
        while ($remaining > 0) {
            $videosPerChunk[$i]++;
            $remaining--;
            $i = ($i + 1) % $numChunks;
        }

        $providersToTry = $provider === 'auto' ? ['pixabay', 'pexels', 'wikimedia', 'archive'] : [$provider];
        $usedUrls = [];
        
        $fallbackPool = [];
        try {
            $pixKey = $apiKey ?: ($this->config['pixabay_api_key'] ?? '');
            if (!empty($pixKey)) {
                $service = new PixabayService($pixKey);
                $res = $service->searchVideos('background abstract nature', 100);
                foreach ($res as $video) {
                    $u = $video['videos']['large']['url'] ?? ($video['videos']['medium']['url'] ?? '');
                    if ($u) $fallbackPool[] = $u;
                }
            }
            if (empty($fallbackPool)) {
                $pexKey = $apiKey ?: ($this->config['pexels_api_key'] ?? '');
                if (!empty($pexKey)) {
                    $service = new PexelsService($pexKey);
                    $res = $service->searchVideos('background abstract nature', 80);
                    foreach ($res as $video) {
                        $files = $video['video_files'] ?? [];
                        foreach ($files as $f) {
                            if (($f['quality'] ?? '') === 'hd' || ($f['quality'] ?? '') === 'sd') {
                                $fallbackPool[] = $f['link'];
                                break;
                            }
                        }
                    }
                }
            }
            if (!empty($fallbackPool)) {
                shuffle($fallbackPool);
            }
        } catch (Throwable $e) {}

        foreach ($chunksToProcess as $index => $chunk) {
            $videosNeeded = $videosPerChunk[$index] ?? 0;
            if ($videosNeeded <= 0) continue;

            $query = $chunk;
            if ($textService) {
                try {
                    $query = $textService->extractStockVideoKeywords($chunk);
                } catch (Throwable $e) {}
            }

            if (strlen($query) > 100) {
                $query = substr($query, 0, 100);
            }

            $results = [];
            $activeProvider = '';

            foreach ($providersToTry as $p) {
                $key = $apiKey ?: ($this->config[$p . '_api_key'] ?? '');
                
                try {
                    if ($p === 'pixabay') {
                        if (empty($key)) continue;
                        $service = new PixabayService($key);
                        $results = $service->searchVideos($query, 40);
                    } elseif ($p === 'pexels') {
                        if (empty($key)) continue;
                        $service = new PexelsService($key);
                        $results = $service->searchVideos($query, 40);
                    } elseif ($p === 'wikimedia') {
                        $service = new WikimediaService();
                        $results = $service->searchVideos($query, 15);
                    } elseif ($p === 'archive') {
                        $service = new InternetArchiveService();
                        $results = $service->searchVideos($query, 15);
                    }

                    if (!empty($results)) {
                        $activeProvider = $p;
                        break;
                    }
                } catch (Throwable $e) {
                    continue;
                }
            }

            $validUrls = [];
            if (!empty($results)) {
                if ($textService && !empty($chunk)) {
                    $optionsDesc = [];
                    foreach (array_slice($results, 0, 10) as $idx => $item) {
                        $desc = '';
                        if ($activeProvider === 'pixabay') {
                            $desc = $item['tags'] ?? '';
                        } elseif ($activeProvider === 'pexels') {
                            $path = parse_url($item['url'] ?? '', PHP_URL_PATH) ?? '';
                            $desc = trim(str_replace('-', ' ', preg_replace('/-\d+\/?$/', '', basename($path))));
                        } elseif ($activeProvider === 'wikimedia' || $activeProvider === 'archive') {
                            $desc = $item['title'] ?? '';
                        }
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

                foreach ($results as $video) {
                    $url = '';
                    if ($activeProvider === 'pixabay') {
                        $url = $video['videos']['large']['url'] ?? ($video['videos']['medium']['url'] ?? ($video['videos']['small']['url'] ?? ($video['videos']['tiny']['url'] ?? '')));
                    } elseif ($activeProvider === 'pexels') {
                        $files = $video['video_files'] ?? [];
                        foreach ($files as $file) {
                            if (($file['quality'] ?? '') === 'hd' || ($file['quality'] ?? '') === 'uhd') {
                                $url = $file['link'];
                                break;
                            }
                        }
                        if (!$url) {
                            foreach ($files as $file) {
                                if (($file['quality'] ?? '') === 'sd') {
                                    $url = $file['link'];
                                    break;
                                }
                            }
                        }
                        if (!$url && !empty($files)) {
                            $url = $files[0]['link'];
                        }
                    } else {
                        $url = $video['url'] ?? '';
                    }

                    if ($url) {
                        $validUrls[] = $url;
                    }
                }
            }

            $uniqueValidUrls = array_diff($validUrls, $usedUrls);
            $uniqueValidUrls = array_values($uniqueValidUrls);

            $j = 0;
            while ($videosNeeded > 0) {
                if (isset($uniqueValidUrls[$j])) {
                    $selectedUrl = $uniqueValidUrls[$j];
                } else {
                    $selectedUrl = array_shift($fallbackPool);
                    while ($selectedUrl && in_array($selectedUrl, $usedUrls)) {
                        $selectedUrl = array_shift($fallbackPool);
                    }
                    if (!$selectedUrl) {
                        $selectedUrl = $validUrls[$j % max(1, count($validUrls))];
                    }
                }
                
                $this->videos[] = $selectedUrl;
                $usedUrls[] = $selectedUrl;
                
                $j++;
                $videosNeeded--;
            }
        }

        if (empty($this->videos)) {
            throw new VideoAutomatorException("Render failed. The scene brief is too complex for this engine. Please try simplifying it.");
        }

        return $this;
    }

    public function addTransitions(string $type = 'fade'): self
    {
        return $this;
    }

    public function export(string $outputPath): bool
    {
        if (empty($this->videos)) {
            throw new VideoAutomatorException("No videos to process. Call fetchStockVideos() first.");
        }

        $outDir = dirname($outputPath);
        if (!is_dir($outDir)) {
            @mkdir($outDir, 0777, true);
        }

        $tempDir = sys_get_temp_dir() . '/video_automator_stock_' . uniqid('', true);
        if (!mkdir($tempDir, 0777, true) && !is_dir($tempDir)) {
            throw new VideoAutomatorException(sprintf('Directory "%s" was not created', $tempDir));
        }

        try {
            $clips = [];
            
            foreach ($this->videos as $index => $videoUrl) {
                $rawPath = $tempDir . "/raw_{$index}.mp4";
                if (!@copy($videoUrl, $rawPath)) {
                    throw new VideoAutomatorException("Failed to download video from: " . $videoUrl);
                }

                $clipPath = $tempDir . "/clip_{$index}.mp4";
                $captionText = !empty($this->captionChunks) ? ($this->captionChunks[$index] ?? '') : ($this->chunks[$index] ?? '');
                $text = ($this->addCaptions && empty($this->voiceOptions)) ? $captionText : '';
                $this->standardizeClip($rawPath, $clipPath, $text);
                
                $clips[] = $clipPath;
            }

            $listPath = $tempDir . '/list.txt';
            $listContent = "";
            foreach ($clips as $clip) {
                $listContent .= "file '" . $clip . "'\n";
            }
            file_put_contents($listPath, $listContent);

            $ffmpegPath = $this->config['ffmpeg_path'] ?? 'ffmpeg';
            $rawOutput = ($this->audioPath || !empty($this->voiceOptions)) ? $tempDir . '/raw_output.mp4' : $outputPath;
            
            $command = [
                $ffmpegPath, '-y', '-f', 'concat', '-safe', '0', '-i', $listPath,
                '-c', 'copy', $rawOutput
            ];

            $process = new Process($command);
            $process->setTimeout(3600);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new VideoAutomatorException("FFMPEG Concat Error: " . $process->getErrorOutput());
            }

            $durationStr = (string)(count($this->videos) * $this->maxClipDuration);

            if (!empty($this->voiceOptions)) {
                $captionsText = implode(' ', $this->captionChunks ?: $this->chunks);
                $voiceService = new AiVoiceService();
                $ttsAudioPath = $tempDir . '/tts.mp3';
                
                $wordTimestamps = $voiceService->generateVoiceoverWithTimestamps(
                    $captionsText, 
                    $this->voiceOptions['provider'], 
                    $this->voiceOptions['model'], 
                    $this->voiceOptions['apiKey'], 
                    $ttsAudioPath
                );

                $mixedAudioPath = $ttsAudioPath;
                if ($this->audioPath && file_exists($this->audioPath)) {
                    $mixedAudioPath = $tempDir . '/mixed.mp3';
                    $mixCmd = [
                        $ffmpegPath, '-y', '-i', $ttsAudioPath, '-i', $this->audioPath,
                        '-filter_complex', '[0:a]volume=1.0[a1];[1:a]volume=0.2[a2];[a1][a2]amix=inputs=2:duration=first',
                        $mixedAudioPath
                    ];
                    $mixProc = new Process($mixCmd);
                    $mixProc->setTimeout(3600);
                    $mixProc->run();
                }

                $assFile = $tempDir . '/subs.ass';
                $assService = new AssSubtitleService();
                $assService->generateAssSubtitles($wordTimestamps, $this->captionStyleOptions, $assFile, $this->width, $this->height);

                $fontPath = $this->config['font_path'] ?? '';
                if (!empty($fontPath) && is_dir(dirname($fontPath))) {
                    $assFilter = sprintf("ass='%s':fontsdir='%s'", str_replace("'", "\\'", $assFile), str_replace("'", "\\'", dirname($fontPath)));
                } else {
                    $assFilter = sprintf("ass='%s'", str_replace("'", "\\'", $assFile));
                }

                $burnCmd = [
                    $ffmpegPath, '-y', '-i', $rawOutput, '-i', $mixedAudioPath,
                    '-filter_complex', "[0:v]{$assFilter}[v]", '-map', '[v]', '-map', '1:a',
                    '-c:a', 'aac', '-b:a', '192k', '-c:v', 'libx264', '-preset', 'fast', '-t', $durationStr,
                    $outputPath
                ];
                $burnProc = new Process($burnCmd);
                $burnProc->setTimeout(3600);
                $burnProc->run();

                if (!$burnProc->isSuccessful()) {
                    throw new VideoAutomatorException("FFMPEG ASS Burn Error: " . $burnProc->getErrorOutput());
                }

            } elseif ($this->audioPath && file_exists($this->audioPath)) {
                $audioCmd = [
                    $ffmpegPath, '-y', '-i', $rawOutput, '-stream_loop', '-1', '-i', $this->audioPath,
                    '-map', '0:v:0', '-map', '1:a:0',
                    '-c:v', 'copy', '-c:a', 'aac', '-shortest', '-t', $durationStr,
                    $outputPath
                ];
                $audioProc = new Process($audioCmd);
                $audioProc->setTimeout(3600);
                $audioProc->run();
                if (!$audioProc->isSuccessful()) {
                    throw new VideoAutomatorException("FFMPEG Audio Merge Error: " . $audioProc->getErrorOutput());
                }
            }

            return true;
        } finally {
            $this->cleanup($tempDir);
        }
    }

    protected function standardizeClip(string $inputPath, string $outputPath, string $text = ''): void
    {
        $ffmpegPath = $this->config['ffmpeg_path'] ?? 'ffmpeg';
        
        $filter = "scale={$this->width}:{$this->height}:force_original_aspect_ratio=increase,crop={$this->width}:{$this->height},setsar=1,fps=25";
        
        if ($text !== '') {
            $limit = $this->getCaptionWordwrapLimit($this->width);
            $text = wordwrap($text, $limit, "\n");
            $txtPath = dirname($outputPath) . '/' . basename($outputPath, '.mp4') . '.txt';
            file_put_contents($txtPath, $text);
            $fontPath = $this->config['font_path'] ?? '';
            
            $safeTxtPath = str_replace(['\\', ':'], ['/', '\\:'], $txtPath);
            
            $filter .= ',' . $this->getCaptionFilter($safeTxtPath, $this->width, $this->height);
        }
        
        $command = [
            $ffmpegPath, '-y', 
            '-stream_loop', '-1',
            '-i', $inputPath,
            '-f', 'lavfi', '-i', 'anullsrc=channel_layout=stereo:sample_rate=44100',
            '-vf', $filter,
            '-map', '0:v:0', '-map', '1:a:0',
            '-c:v', 'libx264', '-preset', 'ultrafast', '-c:a', 'aac', '-t', (string)$this->maxClipDuration, '-pix_fmt', 'yuv420p',
            $outputPath
        ];

        $process = new Process($command);
        $process->setTimeout(3600);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new VideoAutomatorException("Failed to standardize clip: " . $process->getErrorOutput());
        }
    }

    protected function cleanup(string $dir): void
    {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.','..']);
        foreach ($files as $file) {
            @unlink("$dir/$file");
        }
        @rmdir($dir);
    }
}