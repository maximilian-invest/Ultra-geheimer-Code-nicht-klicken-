<?php

namespace Tests\Unit\Expose;

use App\Services\Expose\ExposePaginationService;
use Tests\TestCase;

class ExposePaginationServiceTest extends TestCase
{
    public function test_short_text_below_80_words(): void
    {
        $svc = new ExposePaginationService();
        $this->assertEquals('short', $svc->textFlowMode(str_repeat('wort ', 50)));
    }

    public function test_medium_text_80_to_400_words(): void
    {
        $svc = new ExposePaginationService();
        $this->assertEquals('medium', $svc->textFlowMode(str_repeat('wort ', 200)));
    }

    public function test_long_text_above_400_words(): void
    {
        $svc = new ExposePaginationService();
        $this->assertEquals('long', $svc->textFlowMode(str_repeat('wort ', 500)));
    }

    public function test_empty_text_is_short(): void
    {
        $svc = new ExposePaginationService();
        $this->assertEquals('short', $svc->textFlowMode(''));
        $this->assertEquals('short', $svc->textFlowMode(null));
    }
}
