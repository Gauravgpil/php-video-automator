<?php

namespace PhpVideoAutomator;

use PhpVideoAutomator\Engines\ImageToVideoEngine;
use PhpVideoAutomator\Engines\StockVideoEngine;

class VideoAutomator
{
    protected array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function fromImages(): ImageToVideoEngine
    {
        return new ImageToVideoEngine($this->config);
    }

    public function fromStockVideos(): StockVideoEngine
    {
        return new StockVideoEngine($this->config);
    }
}