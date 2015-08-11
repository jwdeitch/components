<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\ORM\Relations;

use Spiral\ORM\Model;
use Spiral\ORM\Selector;

class HasMany extends HasOne
{
    /**
     * Relation type.
     */
    const RELATION_TYPE = Model::HAS_MANY;

    /**
     * Indication that relation represent multiple records.
     */
    const MULTIPLE = true;

    /**
     * Internal ORM relation method used to create valid selector used to pre-load relation data or
     * create custom query based on relation options.
     *
     * @return Selector
     */
    protected function createSelector()
    {
        $selector = parent::createSelector();

        if (isset($this->definition[Model::WHERE]))
        {
            $selector->where($this->definition[Model::WHERE]);
        }

        return $selector;
    }
}