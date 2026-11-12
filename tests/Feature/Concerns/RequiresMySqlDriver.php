<?php

namespace Tests\Feature\Concerns;

use Illuminate\Support\Facades\DB;

trait RequiresMySqlDriver
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires MySQL.');
        }
    }
}
