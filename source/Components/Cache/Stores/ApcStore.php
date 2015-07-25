<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\Components\Cache\Stores;

use Spiral\Components\Cache\CacheManager;
use Spiral\Components\Cache\CacheStore;

class ApcStore extends CacheStore
{
    /**
     * Internal store name.
     */
    const STORE = 'apc';

    /**
     * Cache driver types.
     */
    const APC_DRIVER  = 0;
    const APCU_DRIVER = 1;

    /**
     * Cache driver type.
     *
     * @var int
     */
    protected $driver = self::APC_DRIVER;

    /**
     * Cache prefix.
     *
     * @var string
     */
    protected $prefix = '';

    /**
     * Create a new cache store instance. Every instance should represent a single cache method.
     * Multiple stores can exist at the same time and be used in different parts of the application.
     *
     * @param CacheManager $cache CacheManager component.
     */
    public function __construct(CacheManager $cache)
    {
        parent::__construct($cache);

        $this->prefix = !empty($this->options['prefix']) ? $this->options['prefix'] . ':' : '';
        $this->driver = function_exists('apcu_store') ? self::APCU_DRIVER : self::APC_DRIVER;
    }

    /**
     * Get APC cache type (APC or APCU).
     *
     * @return int
     */
    public function getDriver()
    {
        return $this->driver;
    }

    /**
     * Check if store is working properly. Should check if the store drives does exist, files are
     * writable, etc.
     *
     * @return bool
     */
    public function isAvailable()
    {
        return function_exists('apcu_store') || function_exists('apc_store');
    }

    /**
     * Check if a value is present in cache.
     *
     * @param string $name Stored value name.
     * @return bool
     */
    public function has($name)
    {
        if ($this->driver == self::APCU_DRIVER)
        {
            return apcu_exists($this->prefix . $name);
        }

        return apc_exists($this->prefix . $name);
    }

    /**
     * Get value stored in cache.
     *
     * @param string $name Stored value name.
     * @return mixed
     */
    public function get($name)
    {
        if ($this->driver == self::APCU_DRIVER)
        {
            return apcu_fetch($this->prefix . $name);
        }

        return apc_fetch($this->prefix . $name);
    }

    /**
     * Set data in cache, should automatically create record if it wasn't created before or replace
     * already existed record.
     *
     *
     * @param string $name     Stored value name.
     * @param mixed  $data     Data in string or binary format.
     * @param int    $lifetime Duration in seconds till value will expire.
     * @return mixed
     */
    public function set($name, $data, $lifetime)
    {
        if ($this->driver == self::APCU_DRIVER)
        {
            return apcu_store($this->prefix . $name, $data, $lifetime);
        }

        return apc_store($this->prefix . $name, $data, $lifetime);
    }

    /**
     * Store value in cache with infinite lifetime. Value will expire only when cache is flushed.
     *
     * @param string $name Stored value name.
     * @param mixed  $data Data in string or binary format.
     * @return mixed
     */
    public function forever($name, $data)
    {
        return $this->set($name, $data, 0);
    }

    /**
     * Delete data from cache. Name will be attached to applicationID to prevent run ins.
     *
     * @param string $name Stored value name.
     */
    public function delete($name)
    {
        if ($this->driver == self::APCU_DRIVER)
        {
            apcu_delete($this->prefix . $name);

            return;
        }

        apc_delete($this->prefix . $name);
    }

    /**
     * Increment numeric value stored in cache.
     *
     * @param string $name  Stored value name.
     * @param int    $delta How much to increment by. 1 by default.
     * @return mixed
     */
    public function increment($name, $delta = 1)
    {
        if ($this->driver == self::APCU_DRIVER)
        {
            return apcu_inc($this->prefix . $name, $delta);
        }

        return apc_inc($this->prefix . $name, $delta);
    }

    /**
     * Decrement numeric value stored in cache.
     *
     * @param string $name  Stored value name.
     * @param int    $delta How much to decrement by. 1 by default.
     * @return mixed
     */
    public function decrement($name, $delta = 1)
    {
        if ($this->driver == self::APCU_DRIVER)
        {
            return apcu_dec($this->prefix . $name, $delta);
        }

        return apc_dec($this->prefix . $name, $delta);
    }

    /**
     * Flush all values stored in cache.
     *
     * @return mixed
     */
    public function flush()
    {
        if ($this->driver == self::APCU_DRIVER)
        {
            apcu_clear_cache();

            return;
        }

        apc_clear_cache('user');
    }
}