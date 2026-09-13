<?php

namespace Tests;

use App\Models\Setting;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Setting menyimpan cache statis per proses; tanpa ini nilai yang ditulis
        // satu tes (mis. batas segmen CRM) terbawa ke tes berikutnya.
        Setting::flushCache();
    }
}
