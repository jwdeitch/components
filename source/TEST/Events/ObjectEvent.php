<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\Events;

class ObjectEvent extends Event
{
    /**
     * Responsible object.
     *
     * @var object
     */
    protected $parent = null;

    /**
     * Event object created automatically via raise() method of EventDispatcher and passed to all
     * handlers listening for this event name. ObjectEvent created by event trait and keeps event
     * parent in "object" property.
     *
     * @param string $name
     * @param object $parent
     * @param mixed  $context
     */
    public function __construct($name, $parent, $context = null)
    {
        $this->name = $name;
        $this->parent = $parent;
        $this->context = $context;
    }

    /**
     * Event context object.
     *
     * @return object
     */
    public function parent()
    {
        return $this->parent;
    }
}