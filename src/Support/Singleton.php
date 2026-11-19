<?php

declare(strict_types=1);

namespace WPE\Support;

/**
 * Shared, lazily created instance for services that hook into WordPress once per request.
 *
 * Each using class gets its own instance: static properties declared in a trait belong to the
 * class that uses it. Constructors stay public so tests and callers can still build their own
 * instances with injected dependencies.
 */
trait Singleton
{
    private static ?self $instance = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }
}
