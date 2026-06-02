<?php

namespace PhpVideoAutomator\Tests\Feature;

use PhpVideoAutomator\Engines\ImageToVideoEngine;
use PhpVideoAutomator\Tests\TestCase;

class ImageToVideoEngineTest extends TestCase
{
    public function test_it_splits_script_into_chunks()
    {
        $engine = new ImageToVideoEngine([]);
        $engine->setScript("Hello world! This is a test. Another sentence here.");

        // Using reflection to check protected property
        $reflection = new \ReflectionClass($engine);
        $property = $reflection->getProperty('chunks');
        $property->setAccessible(true);
        $chunks = $property->getValue($engine);

        $this->assertCount(3, $chunks);
        $this->assertEquals("Hello world!", $chunks[0]);
        $this->assertEquals("This is a test.", $chunks[1]);
        $this->assertEquals("Another sentence here.", $chunks[2]);
    }

    public function test_it_sets_animation_and_captions()
    {
        $engine = new ImageToVideoEngine([]);
        $engine->addAnimation('ken-burns')->withCaptions(true);

        $reflection = new \ReflectionClass($engine);
        
        $animProperty = $reflection->getProperty('animation');
        $animProperty->setAccessible(true);
        $this->assertEquals('ken-burns', $animProperty->getValue($engine));

        $capProperty = $reflection->getProperty('addCaptions');
        $capProperty->setAccessible(true);
        $this->assertTrue($capProperty->getValue($engine));
    }
}
