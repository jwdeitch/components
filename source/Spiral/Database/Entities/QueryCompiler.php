<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 */
namespace Spiral\Database\Entities;

use Spiral\Core\Component;
use Spiral\Database\Exceptions\CompilerException;
use Spiral\Database\Injections\ParameterInterface;
use Spiral\Database\Injections\SQLExpression;
use Spiral\Database\Injections\SQLFragmentInterface;

/**
 * Responsible for conversion of set of query parameters (where tokens, table names and etc) into
 * sql to be send into specific Driver.
 *
 * Source of Compiler must be optimized in nearest future.
 */
class QueryCompiler extends Component
{
    /**
     * Query types for parameter ordering.
     */
    const SELECT_QUERY = 'select';
    const UPDATE_QUERY = 'update';
    const DELETE_QUERY = 'delete';
    const INSERT_QUERY = 'insert';

    /**
     * Cached list of table aliases used to correctly inject prefixed tables into conditions.
     *
     * @var array
     */
    private $aliases = [];

    /**
     * Associated driver instance, may be required for some data assumptions.
     *
     * @var Driver
     */
    protected $driver = null;

    /**
     * Table prefix will be applied to every table name found in query.
     *
     * @var string
     */
    protected $tablePrefix = '';

    /**
     * @param Driver $driver
     * @param string $tablePrefix
     */
    public function __construct(Driver $driver, $tablePrefix = '')
    {
        $this->driver = $driver;
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * Create insert query using table names, columns and rowsets. Must support both - single and
     * batch inserts.
     *
     * @param string $table
     * @param array  $columns
     * @param array  $rowsets
     * @return string
     * @throws CompilerException
     */
    public function insert($table, array $columns, array $rowsets)
    {
        if (empty($columns)) {
            throw new CompilerException("Unable to build insert statement, columns must be set.");
        }

        if (empty($rowsets)) {
            throw new CompilerException(
                "Unable to build insert statement, at least one value set must be provided."
            );
        }

        return "INSERT INTO {$this->quote($table, true)} ({$this->columns($columns)})\n"
        . "VALUES " . join(",\n", $rowsets);
    }

    /**
     * Create update statement.
     *
     * @param string $table
     * @param array  $columns
     * @param array  $where
     * @return string
     * @throws CompilerException
     */
    public function update($table, array $columns, array $where = [])
    {
        $statement = "UPDATE {$this->quote($table, true, true)}\nSET "
            . $this->prepareColumns($columns)
            . $this->mountExpression("\nWHERE", $this->where($where));

        return rtrim($statement);
    }

    /**
     * Create delete statement.
     *
     * @param string $table
     * @param array  $where
     * @return string
     * @throws CompilerException
     */
    public function delete($table, array $where = [])
    {
        $statement = "DELETE FROM {$this->quote($table, true)}"
            . $this->mountExpression("\nWHERE", $this->where($where));

        return rtrim($statement);
    }

    /**
     * Create select statement. Compiler must validly resolve table and column aliases used in
     * conditions and joins.
     *
     * @param array          $from
     * @param boolean|string $distinct String only for PostgresSQL.
     * @param array          $columns
     * @param array          $joins
     * @param array          $where
     * @param array          $having
     * @param array          $groupBy
     * @param array          $orderBy
     * @param int            $limit
     * @param int            $offset
     * @param array          $unions
     * @return string
     * @throws CompilerException
     */
    public function select(
        array $from,
        $distinct,
        array $columns,
        array $joins = [],
        array $where = [],
        array $having = [],
        array $groupBy = [],
        array $orderBy = [],
        $limit = 0,
        $offset = 0,
        array $unions = []
    ) {
        //This statement parts should be processed first to define set of table and column aliases
        $from = $this->tables($from);

        $joins = $this->mountExpression(' ', $this->joins($joins), ' ');
        $distinct = $this->mountExpression(' ', $this->distinct($distinct));

        //After joins and tables to make sure that compiler knows every alias
        $columns = $this->columns($columns);

        $where = $this->mountExpression("\nWHERE", $this->where($where));
        $having = $this->mountExpression("\nHAVING", $this->where($having));
        $groupBy = $this->mountExpression("\nGROUP BY", $this->groupBy($groupBy), ' ');

        //Initial statement have predictable order
        $statement = "SELECT{$distinct}\n{$columns}\nFROM {$from}{$joins}{$where}{$groupBy}{$having}";

        if (empty($unions) && !empty($orderBy)) {
            $statement .= "\nORDER BY " . $this->orderBy($orderBy);
        }

        if (!empty($unions)) {
            $statement .= $this->unions($unions);
        }

        if (!empty($unions) && !empty($orderBy)) {
            $statement .= "\nORDER BY " . $this->orderBy($orderBy);
        }

        if (!empty($limit) || !empty($offset)) {
            $statement .= "\n" . $this->limit($limit, $offset);
        }

        return rtrim($statement);
    }

    /**
     * Query query identifier, if identified stated as table - table prefix must be added.
     *
     * @param string $key        Identifier can include simple column operations and functions,
     *                           having "." in it will automatically force table prefix to first
     *                           value.
     * @param bool   $table      Set to true to let quote method know that identified is related
     *                           to table name.
     * @param bool   $forceTable In some cases we have to force prefix.
     * @return mixed|string
     */
    public function quote($key, $table = false, $forceTable = false)
    {
        if ($key instanceof SQLExpression) {
            return $key->sqlStatement($this);
        } elseif ($key instanceof SQLFragmentInterface) {
            return $key->sqlStatement();
        }

        if (preg_match('/ as /i', $key, $matches)) {
            list($key, $alias) = explode($matches[0], $key);

            /**
             * We can't do looped aliases, so let's force table prefix for identifier if we aliasing
             * table name at this moment.
             */
            $quoted = $this->quote($key, $table, $table)
                . $matches[0]
                . $this->driver->identifier($alias);

            if ($table && strpos($key, '.') === false) {
                //We have to apply operation post factum to prevent self aliasing (name AS name
                //when db has prefix, expected: prefix_name as name)
                $this->aliases[$alias] = $key;
            }

            return $quoted;
        }

        if (strpos($key, '(') || strpos($key, ' ')) {
            return preg_replace_callback('/([a-z][0-9_a-z\.]*\(?)/i',
                function ($identifier) use (&$table) {
                    $identifier = $identifier[1];
                    if (substr($identifier, -1) == '(') {
                        //Function name
                        return $identifier;
                    }

                    if ($table) {
                        $table = false;

                        //Only first table has to be escaped
                        return $this->quote($identifier, true);
                    }

                    return $this->quote($identifier);
                }, $key);
        }

        if (strpos($key, '.') === false) {
            if (($table && !isset($this->aliases[$key])) || $forceTable) {
                if (!isset($this->aliases[$this->tablePrefix . $key])) {
                    $this->aliases[$this->tablePrefix . $key] = $key;
                }

                $key = $this->tablePrefix . $key;
            }

            return $this->driver->identifier($key);
        }

        $key = explode('.', $key);

        //Expecting first element be table name
        if (!isset($this->aliases[$key[0]])) {
            $key[0] = $this->tablePrefix . $key[0];
        }

        //No aliases can be collected there
        $key = array_map([$this->driver, 'identifier'], $key);

        return join('.', $key);
    }

    /**
     * Sort list of parameters in dbms query specific order, query type must be provided. This
     * method was used at times when delete and update queries supported joins, we might drop it
     * now.
     *
     * @param int   $type
     * @param array $where
     * @param array $joins
     * @param array $having
     * @param array $columns
     * @return array
     */
    public function prepareParameters(
        $type,
        array $where = [],
        array $joins = [],
        array $having = [],
        array $columns = []
    ) {
        return array_merge($columns, $joins, $where, $having);
    }

    /**
     * Reset compiler aliases cache.
     *
     * @return $this
     */
    public function reset()
    {
        $this->aliases = [];

        return $this;
    }

    /**
     * Prepare column values to be used in UPDATE statement.
     *
     * @param array  $columns
     * @param string $tableAlias Forced table alias for updated columns.
     * @return array
     */
    protected function prepareColumns(array $columns, $tableAlias = '')
    {
        foreach ($columns as $column => &$value) {
            if ($value instanceof QueryBuilder) {
                $value = '(' . $value->sqlStatement($this) . ')';
            } elseif ($value instanceof SQLExpression) {
                $value = $value->sqlStatement($this);
            } elseif ($value instanceof SQLFragmentInterface) {
                $value = $value->sqlStatement();
            } else {
                $value = '?';
            }

            if (strpos($column, '.') === false && !empty($tableAlias)) {
                $column = $tableAlias . '.' . $column;
            }

            $value = ' ' . $this->quote($column) . ' = ' . $value;

            unset($value);
        }

        return trim(join(", ", $columns));
    }

    /**
     * Compile DISTINCT statement.
     *
     * @param mixed $distinct Not every DBMS support distinct expression, only Postgres does.
     * @return string
     */
    protected function distinct($distinct)
    {
        if (empty($distinct)) {
            return '';
        }

        return "DISTINCT";
    }

    /**
     * Compile table names statement.
     *
     * @param array $tables
     * @return string
     */
    protected function tables(array $tables)
    {
        foreach ($tables as &$table) {
            $table = $this->quote($table, true, true);
            unset($table);
        }

        return join(', ', $tables);
    }

    /**
     * Compile columns list statement.
     *
     * @param array $columns
     * @return string
     */
    protected function columns(array $columns)
    {
        return wordwrap(join(', ', array_map([$this, 'quote'], $columns)), 180);
    }

    /**
     * Compiler joins statement.
     *
     * @param array $joins
     * @return string
     */
    protected function joins(array $joins)
    {
        $statement = '';
        foreach ($joins as $table => $join) {
            $statement .= "\n" . $join['type'] . ' JOIN ' . $this->quote($table, true, true);
            $statement .= $this->mountExpression("\n    ON", $this->where($join['on']));
        }

        return $statement;
    }

    /**
     * Compile where statement.
     *
     * @param array $tokens
     * @return string
     * @throws CompilerException
     */
    protected function where(array $tokens)
    {
        if (empty($tokens)) {
            return '';
        }

        $statement = '';

        $activeGroup = true;
        foreach ($tokens as $condition) {
            $joiner = $condition[0];
            $context = $condition[1];

            //First condition in group/query, no any AND, OR required
            if ($activeGroup) {
                //Kill AND, OR and etc.
                $joiner = '';

                //Next conditions require AND or OR
                $activeGroup = false;
            } else {
                $joiner .= ' ';
            }

            if ($context == '(') {
                //New where group.
                $activeGroup = true;
            }

            if (is_string($context)) {
                $statement = rtrim($statement . $joiner)
                    . ($joiner && $context == '(' ? ' ' : '')
                    . $context
                    . ($context == ')' ? ' ' : '');

                continue;
            }

            if ($context instanceof QueryBuilder) {
                $statement .= $joiner . ' (' . $context->sqlStatement($this) . ') ';
                continue;
            }

            if ($context instanceof SQLExpression) {
                //( ?? )
                $statement .= $joiner . ' ' . $context->sqlStatement($this) . ' ';
                continue;
            } elseif ($context instanceof SQLFragmentInterface) {
                //( ?? )
                $statement .= $joiner . ' ' . $context->sqlStatement() . ' ';
                continue;
            }

            list($identifier, $operator, $value) = $context;
            $identifier = $this->normalizeIdentifier($identifier);

            if ($operator == 'BETWEEN' || $operator == 'NOT BETWEEN') {
                $statement .= "{$joiner} {$identifier} " . "{$operator} "
                    . "{$this->getPlaceholder($value)} AND {$this->getPlaceholder($context[3])} ";

                continue;
            }

            //Resolve operator value for various types
            $operator = $this->resolveOperator($value, $operator);

            if ($value instanceof QueryBuilder) {
                $value = ' (' . $value . ') ';
            } else {
                $value = $this->getPlaceholder($value);
            }

            $statement .= "{$joiner}{$identifier} {$operator} {$value} ";
        }

        if ($activeGroup) {
            throw new CompilerException("Unable to build where statement, unclosed where group.");
        }

        return trim($statement);
    }

    /**
     * Prepare value to be replaced into query (replace ?).
     *
     * @param string $value
     * @return string
     */
    protected function getPlaceholder($value)
    {
        if ($value instanceof SQLExpression) {
            return $value->sqlStatement($this);
        }
        if ($value instanceof SQLFragmentInterface) {
            return $value->sqlStatement();
        }

        return '?';
    }

    /**
     * Compile union statement chunk. Keywords UNION and ALL will be included, this methods will
     * automatically move every union on new line.
     *
     * @param array $unions
     * @return string
     */
    protected function unions(array $unions)
    {
        $statement = '';
        foreach ($unions as $union) {
            $statement .= "\nUNION {$union[1]}\n({$union[0]})";
        }

        return $statement;
    }

    /**
     * Compile ORDER BY statement.
     *
     * @param array $orderBy
     * @return string
     */
    protected function orderBy(array $orderBy)
    {
        $result = [];
        foreach ($orderBy as $item) {
            $result[] = $this->quote($item[0]) . ' ' . strtoupper($item[1]);
        }

        return join(', ', $result);
    }

    /**
     * Compiler GROUP BY statement.
     *
     * @param array $groupBy
     * @return string
     */
    protected function groupBy(array $groupBy)
    {
        $statement = '';
        foreach ($groupBy as $identifier) {
            $statement .= $this->quote($identifier);
        }

        return $statement;
    }

    /**
     * Compile limit statement.
     *
     * @param int $limit
     * @param int $offset
     * @return string
     */
    protected function limit($limit, $offset)
    {
        $statement = '';
        if (!empty($limit)) {
            $statement = "LIMIT {$limit} ";
        }

        if (!empty($offset)) {
            $statement .= "OFFSET {$offset}";
        }

        return trim($statement);
    }

    /**
     * Combine expressing and prefix (usually SQL keyword) but only if expression is not empty.
     *
     * @param string $prefix
     * @param string $expression
     * @param string $postfix
     * @return string
     */
    protected function mountExpression($prefix, $expression, $postfix = '')
    {
        if (empty($expression)) {
            return '';
        }

        return $prefix . ' ' . $expression . $postfix;
    }

    /**
     * Helper method used to interpolate SQL query with set of parameters, must be used only for
     * development purposes and never for real query.
     *
     * @param string $query
     * @param array  $parameters Parameters to be binded into query.
     * @return mixed
     */
    public static function interpolate($query, array $parameters = [])
    {
        if (empty($parameters)) {
            return $query;
        }

        array_walk($parameters, function (&$parameter) {
            return $parameter = self::normalizeParameter($parameter);
        });

        reset($parameters);
        if (!is_int(key($parameters))) {
            return \Spiral\interpolate($query, $parameters, '', '');
        }

        foreach ($parameters as $parameter) {
            $query = preg_replace('/\?/', $parameter, $query, 1);
        }

        return $query;
    }

    /**
     * Normalize identifier value.
     *
     * @param mixed $identifier
     * @return mixed|string
     */
    private function normalizeIdentifier($identifier)
    {
        if ($identifier instanceof QueryBuilder) {
            $identifier = '(' . $identifier->sqlStatement($this) . ')';

            return $identifier;
        } elseif ($identifier instanceof SQLExpression) {
            $identifier = $identifier->sqlStatement($this);

            return $identifier;
        } elseif ($identifier instanceof SQLFragmentInterface) {
            $identifier = $identifier->sqlStatement();

            return $identifier;
        } else {
            $identifier = $this->quote($identifier);

            return $identifier;
        }
    }

    /**
     * Resolve operator value based on value value. ;)
     *
     * @param mixed  $value
     * @param string $operator
     * @return string
     */
    private function resolveOperator($value, $operator)
    {
        if (
            $operator == '='
            && (
                is_array($value)
                || ($value instanceof ParameterInterface && is_array($value->getValue()))
            )
        ) {
            $operator = 'IN';

            return $operator;
        }

        return $operator;
    }

    /**
     * Normalize parameter value to be interpolated.
     *
     * @param mixed $parameter
     * @return string
     */
    protected static function normalizeParameter($parameter)
    {
        if ($parameter instanceof ParameterInterface) {
            return self::normalizeParameter($parameter->getValue());
        }

        switch (gettype($parameter)) {
            case "boolean":
                return $parameter ? 'true' : 'false';
            case "integer":
                return $parameter + 0;
            case "NULL":
                return 'NULL';
            case "double":
                return sprintf('%F', $parameter);
            case "string":
                return "'" . addcslashes($parameter, "'") . "'";
            case 'object':
                if (method_exists($parameter, '__toString')) {
                    return "'" . addcslashes((string)$parameter, "'") . "'";
                }
        }

        return "[UNRESOLVED]";
    }
}