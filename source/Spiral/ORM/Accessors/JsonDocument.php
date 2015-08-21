<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\ORM\Accessors;

use Spiral\Database\Entities\Driver;
use Spiral\ODM\Document;
use Spiral\ORM\RecordAccessorInterface;

/**
 * JsonDocument utilizes abilities of ODM Document record and uses it to represent json values
 * stored inside ORM Record field. You can perform full set of Document operations including sub
 * documents, compositions (even aggregations!), validations and filtering to simplify work with
 * your denormalized data. If you going to use Postgres document fields can even be used in your
 * queries.
 *
 * @see PostgresDriver
 * @see http://www.postgresql.org/docs/9.3/static/datatype-json.html
 * @see http://www.postgresql.org/docs/9.3/static/functions-json.html
 */
abstract class JsonDocument extends Document implements RecordAccessorInterface
{
    /**
     * Let's force solid state... just in case.
     *
     * @var bool
     */
    protected $solidState = true;

    /**
     * {@inheritdoc}
     */
    public function defaultValue(Driver $driver)
    {
        return $this->serializeData();
    }

    /**
     * {@inheritdoc}
     */
    public function serializeData()
    {
        return json_encode(parent::serializeData());
    }

    /**
     * {@inheritdoc}
     */
    public function compileUpdates($field = '')
    {
        //No atomic operations allowed
        return $this->serializeData();
    }

    /**
     * {@inheritdoc}
     */
    public function setData($data)
    {
        if (is_string($data)) {
            $data = json_decode($data);
        }

        return parent::setData($data);
    }
}