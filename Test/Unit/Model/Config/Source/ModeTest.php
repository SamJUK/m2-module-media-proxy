<?php

declare(strict_types=1);

namespace SamJUK\MediaProxy\Test\Unit\Model\Config\Source;

use PHPUnit\Framework\TestCase;

use SamJUK\MediaProxy\Model\Config\Source\Mode;

class ModeTest extends TestCase
{
    public function testEveryModeIsOfferedToTheAdmin(): void
    {
        $values = array_column((new Mode())->toOptionArray(), 'value');

        $this->assertSame([Mode::PROXY, Mode::CACHE, Mode::STREAM], $values);
    }
}
