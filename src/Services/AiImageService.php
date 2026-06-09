<?php

namespace PhpVideoAutomator\Services;

use Exception;
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
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'does not exist') !== false || strpos($e->getMessage(), 'model') !== false) {
                try {
                    $response = $this->client->post('https://api.openai.com/v1/images/generations', [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $this->apiKey,
                            'Content-Type' => 'application/json',
                        ],
                        'json' => [
                            'model' => 'dall-e-2',
                            'prompt' => $prompt,
                            'n' => 1,
                            'size' => '1024x1024'
                        ]
                    ]);

                    $data = json_decode($response->getBody()->getContents(), true);
                    return $data['data'][0]['url'] ?? '';
                } catch (Exception $fallbackException) {
                    throw new VideoAutomatorException("Render failed. Your API account lacks permission for image generation. Please upgrade your API plan or check your billing.");
                }
            }
            throw new VideoAutomatorException("Render failed. The AI engine encountered an error while processing your prompt.");
        }
    }
}
