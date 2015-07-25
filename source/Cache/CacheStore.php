<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\Components\Cache;

use Spiral\Component;
use Spiral\Core\Traits;

abstract class CacheStore extends Component implements StoreInterface
{
    /**
     * Internal store name.
     */
    const STORE = '';

    /**
     * Default store options.
     *
     * @var array
     */
    protected $options = [];

    /**
     * Create a new cache store instance. Every instance should represent a single cache method.
     * Multiple stores can exist at the same time and be used in different parts of the application.
     *
     * @param CacheFacade $cache CacheManager component.
     */
    public function __construct(CacheFacade $cache)
    {
        $this->options = $cache->getStoreOptions(static::STORE) + $this->options;
    }

    /**
     * Check if store is working properly. Should check if the store drives exists, files are
     * writable, etc.
     *
     * @return bool
     */
    abstract public function isAvailable();

    /**
     * Check if value is present in cache.
     *
     * @param string $name Stored value name.
     * @return bool
     */
    abstract public function has($name);

    /**
     * Get value stored in cache.
     *
     * @param string $name Stored value name.
     * @return mixed
     */
    abstract public function get($name);

    /**
     * Set data in cache. This should automatically create a record if it wasn't created before or
     * replace an existing record.
     *
     * @param string $name     Stored value name.
     * @param mixed  $data     Data in string or binary format.
     * @param int    $lifetime Duration in seconds until the value will expire.
     * @return mixed
     */
    abstract public function set($name, $data, $lifetime);

    /**
     * Store value in cache with infinite lifetime. Value will only expire when the cache is flushed.
     *
     * @param string $name Stored value name.
     * @param mixed  $data Data in string or binary format.
     * @return mixed
     */
    abstract public function forever($name, $data);

    /**
     * Delete data from cache.
     *
     * @param string $name Stored value name.
     */
    abstract public function delete($name);

    /**
     * Increment numeric value stored in cache.
     *
     * @param string $name  Stored value name.
     * @param int    $delta How much to increment by. Set to 1 by default.
     * @return mixed
     */
    abstract public function increment($name, $delta = 1);

    /**
     * Decrement numeric value stored in cache.
     *
     * @param string $name  Stored value name.
     * @param int    $delta How much to decrement by. Set to 1 by default.
     * @return mixed
     */
    abstract public function decrement($name, $delta = 1);

    /**
     * Read item from cache and delete it afterwards.
     *
     * @param string $name Stored value name.
     * @return mixed
     */
    public function pull($name)
    {
        $value = $this->get($name);
        $this->delete($name);

        return $value;
    }

    /**
     * Get the item from cache and if the item is missing, set a default value using Closure.
     *
     * @param string   $name     Stored value name.
     * @param int      $lifetime Duration in seconds until the value will expire.
     * @param callback $callback Callback should be called if a value doesn't exist in cache.
     * @return mixed
     */
    public function remember($name, $lifetime, $callback)
    {
        if (!$this->has($name))
        {
            $this->set($name, $value = call_user_func($callback), $lifetime);

            return $value;
        }

        return $this->get($name);
    }

    /**
     * Flush all values stored in cache.
     *
     * @return mixed
     */
    abstract public function flush();
}