<?php

namespace AlexaFers\SystemUtils\Facades;

use Illuminate\Support\Facades\Facade;

class SystemUtils extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'system.performance';
    }
}