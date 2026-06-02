<?php

namespace PhpVideoAutomator\Services;

use GuzzleHttp\Client;
use PhpVideoAutomator\Exceptions\VideoAutomatorException;

class AiImageService
{
    protected string $apiKey;
    protected string $provider;
    protected Client $client;

    public function __construct(string $apiKey, string $provider = 'openai')
    {
        $this->apiKey = $apiKey;
        $this->provider = $provider;
        $this->client = new Client(['timeout' => 60]);
    }

    public function generateImage(string $prompt, string $size = '1024x1024'): string
    {
        if ($this->provider === 'openai') {
            return $this->generateWithOpenAi($prompt, $size);
        }
        
        throw new VideoAutomatorException("Unsupported AI image provider: {$this->provider}");
    }

    protected function generateWithOpenAi(string $prompt, string $size): string
    {
        if (empty($this->apiKey)) {
            return 'https://via.placeholder.com/' . $size . '.png?text=' . urlencode(substr($prompt, 0, 50));
        }

        try {
            $response = $this->client->post('https://api.openai.com/v1/images/generations', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => 'dall-e-3',
                    'prompt' => $prompt,
                    'n' => 1,
                    'size' => $size
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return $data['data'][0]['url'] ?? '';
        } catch (\Exception $e) {
            throw new VideoAutomatorException("Failed to generate AI image: " . $e->getMessage());
        }
    }
}
