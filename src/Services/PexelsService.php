<?php

namespace PhpVideoAutomator\Services;

use GuzzleHttp\Client;
use PhpVideoAutomator\Exceptions\VideoAutomatorException;

class PexelsService
{
    protected string $apiKey;
    protected Client $client;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
        $this->client = new Client([
            'base_uri' => 'https://api.pexels.com/videos/',
            'headers' => [
                'Authorization' => $this->apiKey
            ]
        ]);
    }

    public function searchVideos(string $query, int $perPage = 10): array
    {
        try {
            $response = $this->client->get('search', [
                'query' => [
                    'query' => $query,
                    'per_page' => $perPage,
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return $data['videos'] ?? [];
        } catch (\Exception $e) {
            throw new VideoAutomatorException("Failed to fetch videos from Pexels: " . $e->getMessage());
        }
    }
}
