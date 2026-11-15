<?php

namespace Funseco\Tmx\Tests;

use Funseco\Admin\Tests\Support\AbilitySnapshot;
use Funseco\Core\Testing\TestCase as CoreTestCase;
use Funseco\Records\RecordsServiceProvider;
use Funseco\Jukebox\JukeboxServiceProvider;
use Funseco\Admin\AdminServiceProvider;
use Funseco\Tmx\TmxServiceProvider;

abstract class TestCase extends CoreTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AbilitySnapshot::apply();
    }

    /** Dependencies first, then this plugin (isolated runs load only these). */
    protected function plugins(): array
    {
        return [
            AdminServiceProvider::class,
            JukeboxServiceProvider::class,
            RecordsServiceProvider::class,
            TmxServiceProvider::class,
        ];
    }
}
