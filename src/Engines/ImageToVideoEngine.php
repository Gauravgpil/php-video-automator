<?php

namespace PhpVideoAutomator\Engines;

use Exception;
use Illuminate\Support\Facades\Log;
use PhpVideoAutomator\Exceptions\VideoAutomatorException;
use PhpVideoAutomator\Services\AiImageService;
use PhpVideoAutomator\Services\InternetArchiveService;
use PhpVideoAutomator\Services\PexelsService;
use PhpVideoAutomator\Services\PixabayService;
use PhpVideoAutomator\Services\WikimediaService;
use PhpVideoAutomator\Services\AiTextService;
use Symfony\Component\Process\Process;

class ImageToVideoEngine
{
    protected array $config;
    protected string $script = '';
    protected array $chunks = [];
    protected array $images = [];
    protected bool $addCaptions = false;
    protected string $animation = 'none';
    protected ?string $audioPath = null;
    protected int $width = 1080;
    protected int $height = 1920;
    protected int $imageDuration = 4;

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

        foreach ($this->chunks as $index => $chunk) {
            $query = trim($chunk);
            if ($textService) {
                $query = $textService->extractStockVideoKeywords($chunk);
            }

            if (strlen($query) > 100) {
                $query = substr($query, 0, 100);
            }
            
            $imageUrl = null;
            
            foreach ($providersToTry as $p) {
                $imageUrl = $this->searchProviderForImage($p, $query, true);
                if ($imageUrl) {
                    break;
                }
            }

            if (!$imageUrl) {
                $fallbackQuery = "scenery background abstract";
                foreach ($providersToTry as $p) {
                    $imageUrl = $this->searchProviderForImage($p, $fallbackQuery, false);
                    if ($imageUrl) break;
                }
            }

            if (!$imageUrl) {
                throw new VideoAutomatorException("Could not fetch any stock image for the prompt.");
            }

            $this->images[$index] = $imageUrl;
        }

        return $this;
    }

    private function searchProviderForImage(string $provider, string $query, bool $randomize = true): ?string
    {
        $key = $this->config["{$provider}_api_key"] ?? '';
        if (empty($key) && in_array($provider, ['pixabay', 'pexels'])) return null;

        try {
            if ($provider === 'pixabay') {
                $service = new PixabayService($key);
                $results = $service->searchImages($query, 10);
                if (!empty($results)) {
                    $result = $randomize ? $results[array_rand(array_slice($results, 0, 3))] : $results[0];
                    return $result['largeImageURL'] ?? ($result['webformatURL'] ?? null);
                }
            } elseif ($provider === 'pexels') {
                $service = new PexelsService($key);
                $results = $service->searchImages($query, 10);
                if (!empty($results)) {
                    $result = $randomize ? $results[array_rand(array_slice($results, 0, 3))] : $results[0];
                    return $result['src']['large2x'] ?? ($result['src']['large'] ?? null);
                }
            } elseif ($provider === 'wikimedia') {
                $service = new WikimediaService();
                $results = $service->searchImages($query, 10);
                if (!empty($results)) {
                    $result = $randomize ? $results[array_rand(array_slice($results, 0, 3))] : $results[0];
                    return $result['url'] ?? null;
                }
            } elseif ($provider === 'archive') {
                $service = new InternetArchiveService();
                $results = $service->searchImages($query, 10);
                if (!empty($results)) {
                    $result = $randomize ? $results[array_rand(array_slice($results, 0, 3))] : $results[0];
                    return $result['url'] ?? null;
                }
            }
        } catch (Exception $e) {
            Log::warning("Stock image fallback provider '{$provider}' failed: " . $e->getMessage());
        }

        return null;
    }

    public function addAnimation(string $type = 'zoompan'): self
    {
        $this->animation = $type;
        return $this;
    }

    public function withCaptions(bool $enable = true): self
    {
        $this->addCaptions = $enable;
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

    public function setImageDuration(int $seconds): self
    {
        $this->imageDuration = $seconds;
        return $this;
    }

    public function export(string $outputPath): bool
    {
        if (empty($this->images)) {
            throw new VideoAutomatorException("No images to process. Call generateImages() first.");
        }

        $outDir = dirname($outputPath);
        if (!is_dir($outDir)) {
            @mkdir($outDir, 0777, true);
        }

        $tempDir = sys_get_temp_dir() . '/video_automator_img_' . uniqid('', true);
        if (!mkdir($tempDir, 0777, true) && !is_dir($tempDir)) {
            throw new VideoAutomatorException(sprintf('Directory "%s" was not created', $tempDir));
        }

        try {
            $clips = [];
            
            foreach ($this->images as $index => $imageUrl) {
                $imagePath = $tempDir . "/img_{$index}.jpg";
                if (!@copy($imageUrl, $imagePath)) {
                    throw new VideoAutomatorException("Failed to download generated image.");
                }

                $clipPath = $tempDir . "/clip_{$index}.mp4";
                $text = $this->addCaptions ? $this->chunks[$index] : '';
                $this->createClipFromImage($imagePath, $clipPath, $text);
                
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

    protected function splitIntoChunks(string $script): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+|\n/', $script, -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_filter(array_map('trim', $sentences)));
    }

    protected function createClipFromImage(string $imagePath, string $outputPath, string $text = ''): void
    {
        $ffmpegPath = $this->config['ffmpeg_path'] ?? 'ffmpeg';
        $duration = $this->imageDuration;
        $w2 = $this->width * 2;
        $h2 = $this->height * 2;
        $fps = 25;
        $frames = $duration * $fps;

        if ($this->animation === 'zoompan' || $this->animation === 'ken-burns') {
            $filter = "[0:v]scale={$w2}:{$h2}:force_original_aspect_ratio=increase,crop={$w2}:{$h2},setsar=1";
            
            $effects = [
                "z='min(zoom+0.001,1.5)':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)'",
                "z='if(eq(on,1),1.15,zoom-0.001)':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)'",
                "z='1.1':x='(on/{$frames})*(iw-(iw/zoom))':y='ih/2-(ih/zoom/2)'",
                "z='1.1':x='(1-(on/{$frames}))*(iw-(iw/zoom))':y='ih/2-(ih/zoom/2)'",
                "z='1.1':x='iw/2-(iw/zoom/2)':y='(on/{$frames})*(ih-(ih/zoom))'",
                "z='1.1':x='iw/2-(iw/zoom/2)':y='(1-(on/{$frames}))*(ih-(ih/zoom))'"
            ];
            
            $effect = $effects[array_rand($effects)];
            
            $filter .= ",zoompan={$effect}:d={$frames}:s={$this->width}x{$this->height}:fps={$fps}";
        } else {
            $filter = "[0:v]scale={$this->width}:{$this->height}:force_original_aspect_ratio=increase,crop={$this->width}:{$this->height},setsar=1";
        }

        if ($text !== '') {
            $txtPath = dirname($outputPath) . '/' . basename($outputPath, '.mp4') . '.txt';
            file_put_contents($txtPath, $text);
            $fontPath = $this->config['font_path'] ?? '';
            
            $safeTxtPath = str_replace(['\\', ':'], ['/', '\\:'], $txtPath);
            $safeFontPath = str_replace(['\\', ':'], ['/', '\\:'], $fontPath);
            
            $fontStr = $safeFontPath ? "fontfile='{$safeFontPath}':" : "";
            $filter .= ",drawtext=textfile='{$safeTxtPath}':{$fontStr}fontcolor=white:fontsize=48:box=1:boxcolor=black@0.6:boxborderw=10:x=(w-text_w)/2:y=(h-text_h)-150";
        }

        $command = [
            $ffmpegPath, '-y', '-loop', '1', '-i', $imagePath,
            '-vf', $filter,
            '-c:v', 'libx264', '-t', (string)$duration, '-pix_fmt', 'yuv420p',
            $outputPath
        ];

        $process = new Process($command);
        $process->setTimeout(3600);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new VideoAutomatorException("Failed to create clip: " . $process->getErrorOutput());
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