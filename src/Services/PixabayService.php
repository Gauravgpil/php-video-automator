<?php

namespace PhpVideoAutomator\Services;

use GuzzleHttp\Client;
use PhpVideoAutomator\Exceptions\VideoAutomatorException;

class PixabayService
{
    protected string $apiKey;
    protected Client $client;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
        $this->client = new Client([
            'base_uri' => 'https://pixabay.com/api/',
            'timeout'  => 30,
        ]);
    }

    public function searchVideos(string $query, int $perPage = 10): array
    {
        try {
            $response = $this->client->get('videos/', [
                'query' => [
                    'key' => $this->apiKey,
                    'q' => urlencode($query),
                    'per_page' => $perPage,
                    'safesearch' => 'true'
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return $data['hits'] ?? [];
        } catch (\Exception $e) {
            throw new VideoAutomatorException("Failed to fetch videos from Pixabay: " . $e->getMessage());
        }
    }
}
