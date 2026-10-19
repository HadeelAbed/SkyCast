<?php

namespace Tests\Unit;

use App\Services\ProviderHealth;
use Tests\TestCase;

class ProviderHealthTest extends TestCase
{
    public function test_a_provider_is_marked_down_after_the_threshold_and_reset_on_success(): void
    {
        $health = new ProviderHealth(threshold: 3, cooldownMinutes: 5);

        $this->assertFalse($health->isDown('open_meteo'));

        $health->recordFailure('open_meteo');
        $health->recordFailure('open_meteo');
        $this->assertFalse($health->isDown('open_meteo'));

        $health->recordFailure('open_meteo');
        $this->assertTrue($health->isDown('open_meteo'));

        $health->recordSuccess('open_meteo');
        $this->assertFalse($health->isDown('open_meteo'));
    }

    public function test_failures_are_tracked_per_provider(): void
    {
        $health = new ProviderHealth(threshold: 1, cooldownMinutes: 5);

        $health->recordFailure('open_meteo');

        $this->assertTrue($health->isDown('open_meteo'));
        $this->assertFalse($health->isDown('visual_crossing'));
    }
}
