<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\Database\Builders\Prototypes;

use Psr\Log\LoggerAwareInterface;
use Spiral\Database\Builders\Traits\JoinsTrait;
use Spiral\Database\Entities\Database;
use Spiral\Database\Entities\QueryCompiler;
use Spiral\Debug\Traits\LoggerTrait;

/**
 * Generic prototype for affect queries with WHERE and JOIN supports. At this moment used as parent
 * for delete and update query builders.
 */
abstract class AbstractAffect extends AbstractWhere implements LoggerAwareInterface
{
    /**
     * Spiral allow JOINs in DELETE and UPDATE statements and shows warning where update or delete
     * comes without any limiting condition.
     */
    use LoggerTrait, JoinsTrait;

    /**
     * Every affect builder must be associated with specific table.
     *
     * @var string
     */
    protected $table = '';

    /**
     * {@inheritdoc}
     *
     * @param string $table Associated table name.
     * @param array  $where Initial set of where rules specified as array.
     */
    public function __construct(
        Database $database,
        QueryCompiler $compiler,
        $table = '',
        array $where = []
    )
    {
        parent::__construct($database, $compiler);

        $this->table = $table;
        !empty($where) && $this->where($where);
    }

    /**
     * {@inheritdoc}
     *
     * Affect queries will return count of affected rows.
     *
     * @return int
     */
    public function run()
    {
        if (empty($this->whereTokens) && empty($this->joinTokens))
        {
            $this->logger()->warning("Affect query performed without any limiting condition.");
        }

        return parent::run()->rowCount();
    }
}