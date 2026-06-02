<?php

namespace PhpVideoAutomator\Engines;

use PhpVideoAutomator\Exceptions\VideoAutomatorException;
use PhpVideoAutomator\Services\AiImageService;
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
        $service = new AiImageService($apiKey, $provider);

        foreach ($this->chunks as $index => $chunk) {
            $prompt = "High quality cinematic representation of: " . $chunk;
            $this->images[$index] = $service->generateImage($prompt);
        }

        return $this;
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

    public function export(string $outputPath): bool
    {
        if (empty($this->images)) {
            throw new VideoAutomatorException("No images to process. Call generateImages() first.");
        }

        $tempDir = sys_get_temp_dir() . '/video_automator_img_' . uniqid();
        if (!mkdir($tempDir, 0777, true) && !is_dir($tempDir)) {
            throw new VideoAutomatorException(sprintf('Directory "%s" was not created', $tempDir));
        }

        try {
            $clips = [];
            
            foreach ($this->images as $index => $imageUrl) {
                $imagePath = $tempDir . "/img_{$index}.jpg";
                file_put_contents($imagePath, file_get_contents($imageUrl));

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

    protected function splitIntoChunks(string $script): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+|\n/', $script, -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_filter(array_map('trim', $sentences)));
    }

    protected function createClipFromImage(string $imagePath, string $outputPath, string $text = ''): void
    {
        $ffmpegPath = $this->config['ffmpeg_path'] ?? 'ffmpeg';
        $duration = 4;

        $filter = "[0:v]scale={$this->width}:{$this->height}:force_original_aspect_ratio=decrease,pad={$this->width}:{$this->height}:(ow-iw)/2:(oh-ih)/2,setsar=1";
        
        if ($this->animation === 'zoompan' || $this->animation === 'ken-burns') {
            $filter .= ",zoompan=z='min(zoom+0.0015,1.5)':d={$duration}*25:x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)'";
        }

        if ($text !== '') {
            $escapedText = str_replace(["\\", "'", ":"], ["\\\\", "\\\\'", "\\:"], $text);
            $filter .= ",drawtext=text='{$escapedText}':fontcolor=white:fontsize=48:box=1:boxcolor=black@0.6:boxborderw=10:x=(w-text_w)/2:y=(h-text_h)-150";
        }

        $command = [
            $ffmpegPath, '-y', '-loop', '1', '-i', $imagePath,
            '-vf', $filter,
            '-c:v', 'libx264', '-t', (string)$duration, '-pix_fmt', 'yuv420p',
            $outputPath
        ];

        $process = new Process($command);
        $process->setTimeout(120);
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
            unlink("$dir/$file");
        }
        rmdir($dir);
    }
}