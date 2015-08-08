<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\ORM\Selector\Loaders;

use Spiral\ORM\Model;
use Spiral\ORM\Selector;

class HasManyLoader extends HasOneLoader
{
    /**
     * Relation type is required to correctly resolve foreign model.
     */
    const RELATION_TYPE = Model::HAS_MANY;

    /**
     * Default load method (inload or postload).
     */
    const LOAD_METHOD = Selector::POSTLOAD;

    /**
     * Internal loader constant used to decide nested aggregation level.
     */
    const MULTIPLE = true;

    /**
     * {@inheritdoc}
     */
    protected function mountConditions(Selector $selector)
    {
        $selector = parent::mountConditions($selector);

        //Let's use where decorator to set conditions, it will automatically route tokens to valid
        //destination (JOIN or WHERE)
        $router = new Selector\WhereDecorator(
            $selector,
            $this->isJoined() ? 'onWhere' : 'where',
            $this->getAlias()
        );

        if (!empty($this->definition[Model::WHERE]))
        {
            //Relation WHERE conditions
            $router->where($this->definition[Model::WHERE]);
        }

        //User specified WHERE conditions
        !empty($this->options['where']) && $router->where($this->options['where']);
    }
}