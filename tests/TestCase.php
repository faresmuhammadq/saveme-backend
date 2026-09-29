<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Reference data, the plan catalogue (spec 009, R-8), is present in every
     * Feature and Contract test, as it is in every deployment.
     */
    protected bool $seed = true;
}
