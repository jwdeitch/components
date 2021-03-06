<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 */
namespace Spiral\Events;

use Spiral\Events\Entities\Event;
use Spiral\Events\Exceptions\InvalidArgumentException;

/**
 * Default event dispatcher implementation.
 */
class Dispatcher implements DispatcherInterface
{
    /**
     * Events associated with their listeners.
     *
     * @var array
     */
    private $listeners = [];

    /**
     * {@inheritdoc}
     */
    public function listen($event, $listener)
    {
        if (!isset($this->listeners[$event])) {
            $this->listeners[$event] = [];
        }

        $this->listeners[$event][] = $listener;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function remove($event, $listener)
    {
        if (isset($this->listeners[$event])) {
            unset($this->listeners[$event][array_search($listener, $this->listeners[$event])]);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function hasListener($event, $listener)
    {
        if (isset($this->listeners[$event])) {
            return in_array($listener, $this->listeners[$event]);
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function listeners($event)
    {
        if (array_key_exists($event, $this->listeners)) {
            return $this->listeners[$event];
        }

        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function fire($event, $context = null)
    {
        if (is_object($event) && !($event instanceof EventInterface)) {
            throw new InvalidArgumentException(
                "Only instances of EventInterface or event name can be fired."
            );
        }

        if (is_string($event)) {
            $event = new Event($event, $context);
        }

        /**
         * @var Event $event
         */
        if (empty($this->listeners[$event->name()])) {
            return $event->context();
        }

        foreach ($this->listeners[$event->name()] as $listener) {
            call_user_func($listener, $event);
            if ($event->isStopped()) {
                break;
            }
        }

        return $event->context();
    }
}