# PHP Video Automator

A standalone PHP library to automatically generate videos from text scripts using FFMPEG, AI images, and stock footage. Built exactly to avoid per-generation costs of 3rd party AI video services.

## Features

1. **AI Image-Based Videos (Idea 1):** Splits a script into chunks, generates AI images for each chunk, adds Ken-Burns/zoompan animations, overlays captions, and stitches them into a full MP4.
2. **Stock Footage Videos (Idea 2):** Parses a script to fetch relevant stock videos from Pixabay, randomly selects varied clips to avoid repetition, standardizes formats, and stitches them together seamlessly.

## Installation

```bash
composer require gauravgpil/php-video-automator
```

*Note: You must have `ffmpeg` installed on your server system.*

## Usage

### Setup

Note: `wikimedia` and `archive` providers are 100% free and do not require API keys!

```php
use PhpVideoAutomator\VideoAutomator;

$config = [
    'ffmpeg_path' => '/usr/bin/ffmpeg', // Optional, defaults to 'ffmpeg'
    'ai_image_api_key' => 'sk-your-openai-key',
    'pixabay_api_key' => 'your-pixabay-key',
    'pexels_api_key' => 'your-pexels-key',
    'coverr_api_key' => 'your-coverr-key',
];

$automator = new VideoAutomator($config);
```

### Idea 1: Text to AI Images to Video

```php
$automator->fromImages()
    ->setScript("Welcome to our AI channel. Today we learn about the future.")
    ->generateImages() // Uses OpenAI by default
    ->addAnimation('ken-burns')
    ->withCaptions(true)
    ->export('/var/www/output_ai_video.mp4');
```

### Idea 2: Script to Stock Video (Pixabay, Pexels, Coverr, Wikimedia, Archive)

```php
$automator->fromStockVideos()
    ->setScript("Beautiful waterfalls and lush green nature forests.")
    ->fetchStockVideos('wikimedia', '', ['randomize' => true, 'count' => 3])
    ->addTransitions('fade')
    ->export('/var/www/output_stock_video.mp4');
```

## Testing

Run tests via PHPUnit:

```bash
vendor/bin/phpunit
```
