<?php
namespace App\Traits;

trait AvailableStaticallyTrait
{
    protected static $instance;

    /**
     * @return static
     */
    public static function getInstance(): static
    {
        return self::$instance;
    }

    /**
     * @param static $instance
     */
    public static function setInstance($instance): void
    {
        self::$instance = $instance;
    }
}