<?php

namespace Crunz;

class Singleton {

    /**
     * Class instance
     *
     */
    protected static $instance = null;

    /**
     * Return the instance of the class
     *
     * @return mixed
     */
    public static function getInstance()
    {
        if (is_null(static::$instance)) {
            return new static();
        }

        return static::$instance;
    }

    /**
     * Private clone method to prevent cloning of the instance of the
     * Singleton instance.
     *
     * @return void
     */
    private function __clone()
    {

    }

    /**
     * Legacy unserialization hook.
     *
     * @return void
     */
    public function __wakeup()
    {
    }

}