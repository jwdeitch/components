<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\ORM;

use Spiral\ORM\Exceptions\ORMException;

interface RelationInterface
{
    /**
     * New instance of ORM relation, relations used to represent queries and pre-loaded data inside
     * parent active record, relations by itself not used in query building - but they can be used
     * to create valid query selector.
     *
     * @param ORM          $orm        ORM component.
     * @param Model $parent     Parent ActiveRecord object.
     * @param array        $definition Relation definition.
     * @param mixed        $data       Pre-loaded relation data.
     * @param bool         $loaded     Indication that relation data has been loaded.
     */
    public function __construct(
        ORM $orm,
        Model $parent,
        array $definition,
        $data = null,
        $loaded = false
    );

    /**
     * Reset relation pre-loaded data. By default will flush relation data.
     *
     * @param mixed $data   Pre-loaded relation data.
     * @param bool  $loaded Indication that relation data has been loaded.
     */
    public function reset(array $data = [], $loaded = false);

    /**
     * Check if relation was loaded (even empty).
     *
     * @return bool
     */
    public function isLoaded();

    /**
     * Get relation data (data should be automatically loaded if not pre-loaded already). Result
     * can vary based on relation type and usually represent one model or array of models.
     *
     * @return mixed
     */
    public function getInstance();

    /**
     * Set relation instance (called via __set method of parent ActiveRecord).
     *
     * Example:
     * $user->profile = new Profile();
     *
     * @param Model $instance
     * @throws ORMException
     */
    public function setInstance(Model $instance);

    /**
     * ActiveRecord may ask relation data to be saved, save content will work ONLY for pre-loaded
     * relation content. This method better not be called outside of active record.
     *
     * @param bool $validate
     * @return bool
     */
    public function saveInstance($validate = true);

    /**
     * Get relation data errors (if any).
     *
     * @param bool $reset
     * @return mixed
     */
    public function getErrors($reset = false);

    /**
     * Invoke relation with custom arguments. Result may vary based on relation logic.
     *
     * @param array $arguments
     * @return mixed
     */
    public function __invoke(array $arguments);
}