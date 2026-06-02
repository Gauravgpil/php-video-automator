<?php

namespace PhpVideoAutomator\Services;

use GuzzleHttp\Client;
use PhpVideoAutomator\Exceptions\VideoAutomatorException;

class CoverrService
{
    protected string $apiKey;
    protected Client $client;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
        $this->client = new Client([
            'base_uri' => 'https://api.coverr.co/',
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Accept'        => 'application/json',
            ]
        ]);
    }

    public function searchVideos(string $query, int $perPage = 10): array
    {
        try {
            $response = $this->client->get('videos', [
                'query' => [
                    'query' => $query,
                    'per_page' => $perPage,
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return $data['hits'] ?? $data['data'] ?? $data['videos'] ?? $data ?? [];
        } catch (\Exception $e) {
            throw new VideoAutomatorException("Failed to fetch videos from Coverr: " . $e->getMessage());
        }
    }
}
