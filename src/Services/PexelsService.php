<?php

namespace PhpVideoAutomator\Services;

use Exception;
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
            ],
            'timeout' => 30,
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
        } catch (Exception $e) {
            throw new VideoAutomatorException("Failed to fetch videos from Pexels: " . $e->getMessage());
        }
    }

    public function searchImages(string $query, int $perPage = 10): array
    {
        try {
            $response = $this->client->get('https://api.pexels.com/v1/search', [
                'query' => [
                    'query' => $query,
                    'per_page' => $perPage,
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return $data['photos'] ?? [];
        } catch (Exception $e) {
            throw new VideoAutomatorException("Failed to fetch images from Pexels: " . $e->getMessage());
        }
    }
}
