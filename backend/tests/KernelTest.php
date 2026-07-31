<?php

namespace App\Tests;

use App\Kernel;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class KernelTest extends TestCase
{
    public function testApplicationEnvironmentsAreExplicitlyRestricted(): void
    {
        $kernel = new Kernel('test', true);
        $method = new ReflectionMethod($kernel, 'getAllowedEnvs');

        self::assertSame(
            ['prod', 'dev', 'test'],
            $method->invoke($kernel),
        );
    }
}
