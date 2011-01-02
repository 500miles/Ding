<?php
/**
 * Ding autoloader, you will surely need this.
 *
 * PHP Version 5
 *
 * @category Ding
 * @package  Autoloader
 * @author   Marcelo Gornstein <marcelog@gmail.com>
 * @license  http://www.noneyet.ar/ Apache License 2.0
 * @version  SVN: $Id$
 * @link     http://www.noneyet.ar/
 */

// Check for log4php.
use Ding\Cache\Locator\CacheLocator;
foreach (explode(PATH_SEPARATOR, ini_get('include_path')) as $path) {
    $truePath = implode(
        DIRECTORY_SEPARATOR,
        array($path, 'log4php', 'Logger.php')
    );
    if (file_exists($truePath)) {
        require_once $truePath;
    }
}
// If not found, include our own dummy logger.
if (!class_exists('Logger')) {
    $truePath = implode(
        DIRECTORY_SEPARATOR,
        array('Ding', 'Logger', 'Logger.php')
    );
    require_once $truePath;
}

/**
 * Ding autoloader, you will surely need this.
 *
 * PHP Version 5
 *
 * @category Ding
 * @package  Autoloader
 * @author   Marcelo Gornstein <marcelog@gmail.com>
 * @license  http://www.noneyet.ar/ Apache License 2.0
 * @link     http://www.noneyet.ar/
 */
class Autoloader
{
    private static $_logger;
    
    /**
     * Holds current realpath.
     * @var string
     */
    private static $_myPath;
    
    /**
     * Called by php to load a given class. Returns true if the class was
     * successfully loaded.
     * 
     * @todo Performance: Try to get rid of implode() and str_replace() here.
     * 
     * @return boolean
     */
    public static function load($class)
    {
        $file = implode(
            DIRECTORY_SEPARATOR,
            array(
                self::$_myPath,
                str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php'
            )
        );
        if (!file_exists($file)) {
            return false;
        }
        include_once realpath($file);
        return true;
    }
    
    /**
     * You need to use this function to autoregister this loader.
     * 
     * @see spl_autoload_register()
     * 
     * @return boolean
     */
    public static function register()
    {
        self::$_myPath = implode(
            DIRECTORY_SEPARATOR, 
            array(realpath(dirname(__FILE__)), '..', '..')
        );
        return spl_autoload_register('Autoloader::load');
    }
}
