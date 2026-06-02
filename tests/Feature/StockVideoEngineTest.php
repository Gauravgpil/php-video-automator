<?php

namespace PhpVideoAutomator\Tests\Feature;

use PhpVideoAutomator\Engines\StockVideoEngine;
use PhpVideoAutomator\Tests\TestCase;

class StockVideoEngineTest extends TestCase
{
    public function test_it_can_set_script()
    {
        $engine = new StockVideoEngine([]);
        $engine->setScript("Nature and waterfalls");

        $reflection = new \ReflectionClass($engine);
        $property = $reflection->getProperty('script');
        $property->setAccessible(true);

        $this->assertEquals("Nature and waterfalls", $property->getValue($engine));
    }
}
