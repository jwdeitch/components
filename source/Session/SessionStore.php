<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\Session;

use Psr\Log\LoggerAwareInterface;
use Spiral\Core\ConfiguratorInterface;
use Spiral\Core\ContainerInterface;
use Spiral\Core\Traits\ConfigurableTrait;
use Spiral\Debug\Traits\BenchmarkTrait;
use Spiral\Debug\Traits\LoggerTrait;
use Spiral\Core\Singleton;

class SessionStore extends Singleton implements \ArrayAccess, \IteratorAggregate, LoggerAwareInterface
{
    /**
     * Required traits.
     */
    use ConfigurableTrait, LoggerTrait, BenchmarkTrait;

    /**
     * Declares to IoC that component instance should be treated as singleton.
     */
    const SINGLETON = self::class;

    /**
     * Do not use any custom handlers.
     */
    const NATIVE_HANDLER = 'native';

    /**
     * Container instance.
     *
     * @invisible
     * @var ContainerInterface
     */
    protected $container = null;

    /**
     * Active session id.
     *
     * @var string
     */
    protected $id = '';

    /**
     * Session handled used to handle session operations.
     *
     * @var \SessionHandler
     */
    protected $handler = null;

    /**
     * Is store already started.s
     *
     * @var bool
     */
    protected $started = false;

    /**
     * Check if session were destroyed.
     *
     * @var bool
     */
    protected $destroyed = false;

    /**
     * SessionStore is singleton object used to manager sessions using default/non default session
     * handlers. Attention, session store based on php sessions, so it's REALLY important to have
     * only one store at one moment of time. You still can have multiple session using http->perform
     * and nested requests, but it will be non efficient.
     *
     * @param ConfiguratorInterface $configurator
     * @param ContainerInterface    $container
     */
    public function __construct(ConfiguratorInterface $configurator, ContainerInterface $container)
    {
        $this->config = $configurator->getConfig($this);
        $this->container = $container;
    }

    /**
     * Update session id.
     *
     * @param string $id
     */
    public function setID($id)
    {
        if ($this->id != $id)
        {
            $this->id = $id;
            if ($this->started)
            {
                $this->commit();
                $this->start();
            }

            if ($this->destroyed)
            {
                $this->destroyed = false;
                $this->start();
            }
        }
    }

    /**
     * Current session id.
     *
     * @param bool $start Automatically start session.
     * @return string
     */
    public function getID($start = true)
    {
        if (!$this->started && $start)
        {
            $this->start();
        }

        return $this->id;
    }

    /**
     * Check is session store started.
     *
     * @return bool
     */
    public function isStarted()
    {
        return $this->started;
    }

    /**
     * Check is session store were destroyed.
     *
     * @return bool
     */
    public function isDestroyed()
    {
        return $this->destroyed;
    }

    /**
     * Session handler instance.
     *
     * @return \SessionHandler|null
     */
    public function getHandler()
    {
        $this->start();

        return $this->handler;
    }

    /**
     * Starting new session or restoring old session with specified session id.
     *
     * @param \SessionHandler $handler Manually provided session handler.
     * @return bool
     */
    public function start(\SessionHandler $handler = null)
    {
        if ($this->started)
        {
            return true;
        }

        //We don't need cookies
        ini_set('session.use_cookies', false);

        !empty($this->id) && session_id($this->id);

        if (empty($handler))
        {
            $defaultHandler = $this->config['handler'];

            if ($defaultHandler != self::NATIVE_HANDLER)
            {
                $config = $this->config['handlers'][$this->config['handler']];
                $handler = $this->handler = $this->container->get($config['class'], [
                    'options'  => $config,
                    'lifetime' => $this->config['lifetime']
                ], null, true);
            }
        }

        if (!empty($handler))
        {
            //Custom session handler
            session_set_save_handler($handler, true);
        }

        try
        {
            $this->benchmark('start');
            session_start();
            $this->benchmark('start');

            $this->id = session_id();
            $this->started = true;
            $this->destroyed = false;
        }
        catch (\ErrorException $exception)
        {
            $this->id = '';

            $this->logger()->error($exception->getMessage());

            //Invalid character possibly provided
            return false;
        }

        return true;
    }

    /**
     * Regenerate session id.
     *
     * @param bool $deleteOld
     */
    public function regenerateID($deleteOld = false)
    {
        $this->benchmark('regenerateID');
        session_regenerate_id($deleteOld);
        $this->benchmark('regenerateID');

        $this->id = session_id();
    }

    /**
     * Commit all session data to session handler, this will close session before script ends. Session
     * will be restarted on next call.
     */
    public function commit()
    {
        $this->benchmark('commit');
        $this->started && session_write_close();
        $this->benchmark('commit');

        $this->started = false;
    }

    /**
     * Destroys all of the data associated with the current session. It does not unset any of the
     * global variables associated with the session, or unset the session cookie. To use the session
     * variables again, SessionStore->start() has to be called.
     */
    public function destroy()
    {
        if (empty($this->id))
        {
            return;
        }

        $this->benchmark('destroy');
        $this->started && session_destroy();
        $this->benchmark('destroy');

        $this->id = '';
        $this->destroyed = true;
        $this->started = false;
    }

    /**
     * Check if value presented in session.
     *
     * @param string $name Session value name.
     * @return bool
     */
    public function has($name)
    {
        return $this->start() && isset($_SESSION[$name]);
    }

    /**
     * Get value stored in session.
     *
     * @param string $name    Session value name.
     * @param mixed  $default Default session value.
     * @return mixed
     */
    public function &get($name, $default = null)
    {
        $this->start();

        if (!isset($_SESSION[$name]))
        {
            $_SESSION[$name] = $default;
        }

        return $_SESSION[$name];
    }

    /**
     * Set data in session. Value will be immediately available via $_SESSION array.
     *
     * @param string $name  Session value name.
     * @param mixed  $value Session value.
     * @return mixed
     */
    public function set($name, $value)
    {
        $this->start() && ($_SESSION[$name] = $value);
    }

    /**
     * Delete data from session.
     *
     * @param string $name Session value name.
     */
    public function delete($name)
    {
        $this->start();
        unset($_SESSION[$name]);
    }

    /**
     * Read item from session and delete it after.
     *
     * @param string $name Session value name.
     * @return mixed
     */
    public function pull($name)
    {
        $value = $this->get($name);
        $this->delete($name);

        return $value;
    }

    /**
     * Decrypt all existed cookies and return all values associated with their names.
     *
     * @return array
     */
    public function all()
    {
        return $this->start() ? $_SESSION : [];
    }

    /**
     * __get, alias for Session::get() method.
     *
     * @param string $name Session value name.
     * @return mixed
     */
    public function &__get($name)
    {
        return $this->get($name);
    }

    /**
     * __set, alias for Session::set() method.
     *
     * @param string $name  Session value name.
     * @param mixed  $value Session name.
     */
    public function __set($name, $value)
    {
        $this->set($name, $value);
    }

    /**
     * Whether a offset exists.
     *
     * @link http://php.net/manual/en/arrayaccess.offsetexists.php
     * @param mixed $name An offset to check for.
     * @return bool
     */
    public function __isset($name)
    {
        return isset($_SESSION[$name]);
    }

    /**
     * __unset, alias for Session::delete() method.
     *
     * @param string $name
     */
    public function __unset($name)
    {
        $this->delete($name);
    }

    /**
     * Whether a offset exists.
     *
     * @link http://php.net/manual/en/arrayaccess.offsetexists.php
     * @param mixed $offset An offset to check for.
     * @return boolean
     */
    public function offsetExists($offset)
    {
        return $this->__isset($offset);
    }

    /**
     * Offset to retrieve.
     *
     * @link http://php.net/manual/en/arrayaccess.offsetget.php
     * @param mixed $offset The offset to retrieve.
     * @return mixed
     */
    public function offsetGet($offset)
    {
        return $this->__get($offset);
    }

    /**
     * Offset to set.
     *
     * @link http://php.net/manual/en/arrayaccess.offsetset.php
     * @param mixed $offset The offset to assign the value to.
     * @param mixed $value  The value to set.
     * @return void
     */
    public function offsetSet($offset, $value)
    {
        $this->__set($offset, $value);
    }

    /**
     * Offset to unset.
     *
     * @link http://php.net/manual/en/arrayaccess.offsetunset.php
     * @param mixed $offset The offset to unset.
     * @return void
     */
    public function offsetUnset($offset)
    {
        $this->__unset($offset);
    }

    /**
     * Retrieve an external iterator. An instance of an object implementing Iterator or Traversable.
     *
     * @link http://php.net/manual/en/iteratoraggregate.getiterator.php
     * @return \Traversable
     */
    public function getIterator()
    {
        return new \ArrayIterator($_SESSION);
    }
}