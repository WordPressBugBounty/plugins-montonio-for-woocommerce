<?php

defined('ABSPATH') || exit;

/**
 * Class Montonio_Singleton
 * @since 7.0.0
 */
abstract class Montonio_Singleton {
    
    /**
     * @since 7.0.0
     * @var array The instances of the classes
     */
    private static $instances = [];

    /**
     * Montonio_Singleton constructor.
     * 
     * @since 7.0.0
     */
    protected function __construct() {}

    /**
     * Get the instance of the class
     * 
     * @since 7.0.0
     * @return mixed The instance of the class
     */
    public static function get_instance() {
        $class = get_called_class();

        if ( ! isset( self::$instances[$class] ) ) {
            self::$instances[$class] = new $class();
        }

        return self::$instances[$class];
    }
}
