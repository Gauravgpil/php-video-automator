<?php

namespace PhpVideoAutomator\Engines;

use PhpVideoAutomator\Exceptions\VideoAutomatorException;
use PhpVideoAutomator\Services\PixabayService;
use PhpVideoAutomator\Services\PexelsService;
use PhpVideoAutomator\Services\WikimediaService;
use PhpVideoAutomator\Services\InternetArchiveService;
use PhpVideoAutomator\Services\AiTextService;
use Symfony\Component\Process\Process;
use Throwable;

class StockVideoEngine
{
    protected array $config;
    protected string $script = '';
    protected array $videos = [];
    protected ?string $audioPath = null;
    protected int $width = 1080;
    protected int $height = 1920;
    protected int $maxClipDuration = 5;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    protected array $chunks = [];

    public function setScript(string $script): self
    {
        $this->script = $script;
        $this->chunks = $this->splitIntoChunks($script);
        return $this;
    }

    protected function splitIntoChunks(string $script): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+|\n/', $script, -1, PREG_SPLIT_NO_EMPTY);
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

    public function setMaxClipDuration(int $seconds): self
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

        foreach ($chunksToProcess as $index => $chunk) {
            $videosNeeded = $videosPerChunk[$index] ?? 0;
            if ($videosNeeded <= 0) continue;

            $query = $chunk;
            if ($textService) {
                $query = $textService->extractStockVideoKeywords($chunk);
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
                        $results = $service->searchVideos($query, 15);
                    } elseif ($p === 'pexels') {
                        if (empty($key)) continue;
                        $service = new PexelsService($key);
                        $results = $service->searchVideos($query, 15);
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

            if ($randomize && !empty($results)) {
                shuffle($results);
            }

            $selected = array_slice($results, 0, $videosNeeded);

            foreach ($selected as $video) {
                $url = '';
                if ($activeProvider === 'pixabay') {
                    $url = $video['videos']['tiny']['url'] ?? '';
                } elseif ($activeProvider === 'pexels') {
                    $files = $video['video_files'] ?? [];
                    foreach ($files as $file) {
                        if (($file['quality'] ?? '') === 'sd') {
                            $url = $file['link'];
                            break;
                        }
                    }
                    if (!$url && !empty($files)) {
                        $url = $files[0]['link'];
                    }
                } else {
                    $url = $video['url'] ?? '';
                }

                if ($url) {
                    $this->videos[] = $url;
                }
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
                $this->standardizeClip($rawPath, $clipPath);
                
                $clips[] = $clipPath;
            }

            $listPath = $tempDir . '/list.txt';
            $listContent = "";
            foreach ($clips as $clip) {
                $listContent .= "file '" . $clip . "'\n";
            }
            file_put_contents($listPath, $listContent);

            $ffmpegPath = $this->config['ffmpeg_path'] ?? 'ffmpeg';
            $rawOutput = $this->audioPath ? $tempDir . '/raw_output.mp4' : $outputPath;
            
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

            if ($this->audioPath && file_exists($this->audioPath)) {
                $audioCmd = [
                    $ffmpegPath, '-y', '-i', $rawOutput, '-i', $this->audioPath,
                    '-c:v', 'copy', '-c:a', 'aac', '-map', '0:v:0', '-map', '1:a:0', '-shortest',
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

    protected function standardizeClip(string $inputPath, string $outputPath): void
    {
        $ffmpegPath = $this->config['ffmpeg_path'] ?? 'ffmpeg';
        
        $filter = "scale={$this->width}:{$this->height}:force_original_aspect_ratio=increase,crop={$this->width}:{$this->height},setsar=1,fps=25";
        
        $command = [
            $ffmpegPath, '-y', 
            '-i', $inputPath,
            '-f', 'lavfi', '-i', 'anullsrc=channel_layout=stereo:sample_rate=44100',
            '-vf', $filter,
            '-c:v', 'libx264', '-c:a', 'aac', '-t', (string)$this->maxClipDuration, '-pix_fmt', 'yuv420p',
            '-shortest',
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