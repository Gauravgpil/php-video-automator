<?php

namespace PhpVideoAutomator\Engines;

use PhpVideoAutomator\Exceptions\VideoAutomatorException;
use PhpVideoAutomator\Services\PixabayService;
use PhpVideoAutomator\Services\PexelsService;
use PhpVideoAutomator\Services\CoverrService;
use PhpVideoAutomator\Services\WikimediaService;
use PhpVideoAutomator\Services\InternetArchiveService;
use Symfony\Component\Process\Process;

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

    public function setScript(string $script): self
    {
        $this->script = $script;
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

    public function setMaxClipDuration(int $seconds): self
    {
        $this->maxClipDuration = $seconds;
        return $this;
    }

    public function fetchStockVideos(string $provider = 'pixabay', string $apiKey = '', array $options = []): self
    {
        $apiKey = $apiKey ?: ($this->config[$provider . '_api_key'] ?? '');
        
        $query = substr($this->script, 0, 50);
        $results = [];

        if ($provider === 'pixabay') {
            $service = new PixabayService($apiKey);
            $results = $service->searchVideos($query, 15);
        } elseif ($provider === 'pexels') {
            $service = new PexelsService($apiKey);
            $results = $service->searchVideos($query, 15);
        } elseif ($provider === 'coverr') {
            $service = new CoverrService($apiKey);
            $results = $service->searchVideos($query, 15);
        } elseif ($provider === 'wikimedia') {
            $service = new WikimediaService();
            $results = $service->searchVideos($query, 15);
        } elseif ($provider === 'archive') {
            $service = new InternetArchiveService();
            $results = $service->searchVideos($query, 15);
        } else {
            throw new VideoAutomatorException("Unsupported stock video provider: $provider");
        }

        if (empty($results)) {
            throw new VideoAutomatorException("No videos found on $provider for query: $query");
        }

        $randomize = $options['randomize'] ?? true;
        $count = $options['count'] ?? 3;

        if ($randomize) {
            shuffle($results);
        }

        $selected = array_slice($results, 0, $count);

        foreach ($selected as $video) {
            $url = '';
            
            if ($provider === 'pixabay') {
                $url = $video['videos']['tiny']['url'] ?? '';
            } elseif ($provider === 'pexels') {
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
            } elseif ($provider === 'coverr') {
                $url = $video['urls']['mp4'] ?? $video['video_url'] ?? $video['src'] ?? '';
            } elseif ($provider === 'wikimedia' || $provider === 'archive') {
                $url = $video['url'] ?? '';
            }

            if ($url) {
                $this->videos[] = $url;
            }
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

        $tempDir = sys_get_temp_dir() . '/video_automator_stock_' . uniqid();
        if (!mkdir($tempDir, 0777, true) && !is_dir($tempDir)) {
            throw new VideoAutomatorException(sprintf('Directory "%s" was not created', $tempDir));
        }

        try {
            $clips = [];
            
            foreach ($this->videos as $index => $videoUrl) {
                $rawPath = $tempDir . "/raw_{$index}.mp4";
                file_put_contents($rawPath, file_get_contents($videoUrl));

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
            $process->setTimeout(300);
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
                $audioProc->setTimeout(300);
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
        
        $filter = "scale={$this->width}:{$this->height}:force_original_aspect_ratio=decrease,pad={$this->width}:{$this->height}:(ow-iw)/2:(oh-ih)/2,setsar=1,fps=25";
        
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
        $process->setTimeout(120);
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
            unlink("$dir/$file");
        }
        rmdir($dir);
    }
}
