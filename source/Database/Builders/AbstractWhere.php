<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\Database\Builders;

use Spiral\Database\DatabaseException;
use Spiral\Database\Parameter;
use Spiral\Database\ParameterInterface;
use Spiral\Database\QueryBuilder;
use Spiral\Database\SqlFragmentInterface;

abstract class AbstractWhere extends QueryBuilder
{
    /**
     * QueryBuilder constants. This particular constants used in WhereTrait to convert array query
     * to where tokens.
     */
    const TOKEN_AND = "@AND";
    const TOKEN_OR  = "@OR";

    /**
     * WhereTrait organize where construction using token structure which includes token joiner (OR,
     * AND) and token context, this set of tokens can be used to represent almost any query string
     * and can be compiled by QueryGrammar->compileWhere() method. Even if token context will contain
     * original value, this value will be replaced with placeholder in generated query.
     *
     * @var array
     */
    protected $whereTokens = [];

    /**
     * Binded query WHERE parameters.
     *
     * @var array
     */
    protected $whereParameters = [];

    /**
     * Add where condition to statement. Where condition will be specified with AND boolean joiner.
     * Method supports nested queries and array based (mongo like) where conditions. Every provided
     * parameter will be automatically escaped in generated query.
     *
     * Examples:
     * 1) Simple token/nested query or expression
     * $select->where(new SQLFragment('(SELECT count(*) from `table`)'));
     *
     * 2) Simple assessment (= or IN)
     * $select->where('column', $value);
     * $select->where('column', array(1, 2, 3));
     * $select->where('column', new SQLFragment('CONCAT(columnA, columnB)'));
     *
     * 3) Assessment with specified operator (operator will be converted to uppercase automatically)
     * $select->where('column', '=', $value);
     * $select->where('column', 'IN', array(1, 2, 3));
     * $select->where('column', 'LIKE', $string);
     * $select->where('column', 'IN', new SQLFragment('(SELECT id from `table` limit 1)'));
     *
     * 4) Between and not between statements
     * $select->where('column', 'between', 1, 10);
     * $select->where('column', 'not between', 1, 10);
     * $select->where('column', 'not between', new SQLFragment('MIN(price)'), $maximum);
     *
     * 5) Closure with nested conditions
     * $this->where(function(WhereTrait $select){
     *      $select->where("name", "Wolfy-J")->orWhere("balance", ">", 100)
     * });
     *
     * 6) Array based condition
     * $select->where(["column" => 1]);
     * $select->where(["column" => [">" => 1, "<" => 10]]);
     * $select->where([
     *      "@or" => [
     *          ["id" => 1],
     *          ["column" => ["like" => "name"]]
     *      ]
     * ]);
     * $select->where([
     *      '@or' => [
     *          ["id" => 1],
     *          ["id" => 2],
     *          ["id" => 3],
     *          ["id" => 4],
     *          ["id" => 5],
     *      ],
     *      "column" => [
     *          "like" => "name"
     *      ],
     *      'x'      => [
     *          '>' => 1,
     *          '<' => 10
     *      ]
     * ]);
     *
     * You can read more about complex where statements in official documentation or look mongo
     * queries examples.
     *
     * @see parseWhere()
     * @see whereToken()
     * @param string|mixed $identifier Column or expression.
     * @param mixed        $variousA   Operator or value.
     * @param mixed        $variousB   Value is operator specified.
     * @param mixed        $variousC   Specified only in between statements.
     * @return $this
     * @throws DatabaseException
     */
    public function where($identifier, $variousA = null, $variousB = null, $variousC = null)
    {
        $this->whereToken('AND', func_get_args(), $this->whereTokens);

        return $this;
    }

    /**
     * Add where condition to statement. Where condition will be specified with AND boolean joiner.
     * Method supports nested queries and array based (mongo like) where conditions. Every provided
     * parameter will be automatically escaped in generated query. Alias for where.
     *
     * Examples:
     * 1) Simple token/nested query or expression
     * $select->andWhere(new SQLFragment('(SELECT count(*) from `table`)'));
     *
     * 2) Simple assessment (= or IN)
     * $select->andWhere('column', $value);
     * $select->andWhere('column', array(1, 2, 3));
     * $select->andWhere('column', new SQLFragment('CONCAT(columnA, columnB)'));
     *
     * 3) Assessment with specified operator (operator will be converted to uppercase automatically)
     * $select->andWhere('column', '=', $value);
     * $select->andWhere('column', 'IN', array(1, 2, 3));
     * $select->andWhere('column', 'LIKE', $string);
     * $select->andWhere('column', 'IN', new SQLFragment('(SELECT id from `table` limit 1)'));
     *
     * 4) Between and not between statements
     * $select->andWhere('column', 'between', 1, 10);
     * $select->andWhere('column', 'not between', 1, 10);
     * $select->andWhere('column', 'not between', new SQLFragment('MIN(price)'), $maximum);
     *
     * 5) Closure with nested conditions
     * $this->andWhere(function(WhereTrait $select){
     *      $select->where("name", "Wolfy-J")->orWhere("balance", ">", 100)
     * });
     *
     * 6) Array based condition
     * $select->andWhere(["column" => 1]);
     * $select->andWhere(["column" => [">" => 1, "<" => 10]]);
     * $select->andWhere([
     *      "id" => 1,
     *      "column" => ["like" => "name"]
     * ]);
     * $select->andWhere([
     *      '@or' => [
     *          ["id" => 1],
     *          ["id" => 2],
     *          ["id" => 3],
     *          ["id" => 4],
     *          ["id" => 5],
     *      ],
     *      "column" => [
     *          "like" => "name"
     *      ],
     *      'x'      => [
     *          '>' => 1,
     *          '<' => 10
     *      ]
     * ]);
     *
     * You can read more about complex where statements in official documentation or look mongo
     * queries examples.
     *
     * @see parseWhere()
     * @see whereToken()
     * @param string|mixed $identifier Column or expression.
     * @param mixed        $variousA   Operator or value.
     * @param mixed        $variousB   Value is operator specified.
     * @param mixed        $variousC   Specified only in between statements.
     * @return $this
     * @throws DatabaseException
     */
    public function andWhere($identifier, $variousA = null, $variousB = null, $variousC = null)
    {
        $this->whereToken('AND', func_get_args(), $this->whereTokens);

        return $this;
    }

    /**
     * Add where condition to statement. Where condition will be specified with OR boolean joiner.
     * Method supports nested queries and array based (mongo like) where conditions. Every provided
     * parameter will be automatically escaped in generated query.
     *
     * Examples:
     * 1) Simple token/nested query or expression
     * $select->orWhere(new SQLFragment('(SELECT count(*) from `table`)'));
     *
     * 2) Simple assessment (= or IN)
     * $select->orWhere('column', $value);
     * $select->orWhere('column', array(1, 2, 3));
     * $select->orWhere('column', new SQLFragment('CONCAT(columnA, columnB)'));
     *
     * 3) Assessment with specified operator (operator will be converted to uppercase automatically)
     * $select->orWhere('column', '=', $value);
     * $select->orWhere('column', 'IN', array(1, 2, 3));
     * $select->orWhere('column', 'LIKE', $string);
     * $select->orWhere('column', 'IN', new SQLFragment('(SELECT id from `table` limit 1)'));
     *
     * 4) Between and not between statements
     * $select->orWhere('column', 'between', 1, 10);
     * $select->orWhere('column', 'not between', 1, 10);
     * $select->orWhere('column', 'not between', new SQLFragment('MIN(price)'), $maximum);
     *
     * 5) Closure with nested conditions
     * $this->orWhere(function(WhereTrait $select){
     *      $select->where("name", "Wolfy-J")->orWhere("balance", ">", 100)
     * });
     *
     * 6) Array based condition
     * $select->orWhere(["column" => 1]);
     * $select->orWhere(["column" => [">" => 1, "<" => 10]]);
     * $select->orWhere([
     *      "id" => 1,
     *      "column" => ["like" => "name"]
     * ]);
     * $select->orWhere([
     *      '@or' => [
     *          ["id" => 1],
     *          ["id" => 2],
     *          ["id" => 3],
     *          ["id" => 4],
     *          ["id" => 5],
     *      ],
     *      "column" => [
     *          "like" => "name"
     *      ],
     *      'x'      => [
     *          '>' => 1,
     *          '<' => 10
     *      ]
     * ]);
     *
     * You can read more about complex where statements in official documentation or look mongo
     * queries examples.
     *
     * @see parseWhere()
     * @see whereToken()
     * @param string|mixed $identifier Column or expression.
     * @param mixed        $variousA   Operator or value.
     * @param mixed        $variousB   Value is operator specified.
     * @param mixed        $variousC   Specified only in between statements.
     * @return $this
     * @throws DatabaseException
     */
    public function orWhere($identifier, $variousA = [], $variousB = null, $variousC = null)
    {
        $this->whereToken('OR', func_get_args(), $this->whereTokens);

        return $this;
    }

    /**
     * Helper methods used to processed user input in where methods to internal where token, method
     * support all different combinations, closures and nested queries. Additionally i can be used
     * not only for where but for having and join tokens.
     *
     * @param string        $joiner     Boolean joiner (AND|OR).
     * @param array         $parameters Set of parameters collected from where functions.
     * @param array         $tokens     Array to aggregate compiled tokens.
     * @param \Closure|null $wrapper    Callback or closure used to handle all catched
     *                                  parameters, by default $this->addParameter will be used.
     * @return array
     * @throws DatabaseException
     */
    protected function whereToken(
        $joiner,
        array $parameters,
        &$tokens = [],
        callable $wrapper = null
    )
    {
        if (empty($wrapper))
        {
            $wrapper = $this->whereWrapper();
        }

        list($identifier, $valueA, $valueB, $valueC) = $parameters + array_fill(0, 5, null);

        if (empty($identifier))
        {
            //Nothing to do
            return $tokens;
        }

        //Complex query is provided
        if (is_array($identifier))
        {
            $tokens[] = [$joiner, '('];
            $this->parseWhere($identifier, self::TOKEN_AND, $tokens, $wrapper);
            $tokens[] = ['', ')'];

            return $tokens;
        }

        if ($identifier instanceof \Closure)
        {
            $tokens[] = [$joiner, '('];
            call_user_func($identifier, $this, $joiner, $wrapper);
            $tokens[] = ['', ')'];

            return $tokens;
        }

        if ($identifier instanceof QueryBuilder)
        {
            //This will copy all parameters from QueryBuilder
            $wrapper($identifier);
        }

        switch (count($parameters))
        {
            case 1:
                //A single token, usually sub query
                $tokens[] = [$joiner, $identifier];
                break;
            case 2:
                //Simple condition
                $tokens[] = [
                    $joiner,
                    [
                        $identifier,
                        '=',
                        //Check if sql fragment
                        $wrapper($valueA)
                    ]
                ];
                break;
            case 3:
                //Operator is specified
                $tokens[] = [
                    $joiner,
                    [
                        $identifier,
                        strtoupper($valueA),
                        $wrapper($valueB)
                    ]
                ];
                break;
            case 4:
                //BETWEEN or NOT BETWEEN
                $valueA = strtoupper($valueA);
                if (!in_array($valueA, ['BETWEEN', 'NOT BETWEEN']))
                {
                    throw new DatabaseException(
                        'Only "BETWEEN" or "NOT BETWEEN" can define second comparasions value.'
                    );
                }

                $tokens[] = [
                    $joiner,
                    [
                        $identifier,
                        strtoupper($valueA),
                        $wrapper($valueB),
                        $wrapper($valueC)
                    ]
                ];
        }

        return $tokens;
    }

    /**
     * Used to wrap and collect parameters used in where conditions, by default this parameters will
     * be passed though addParameter() method of current query builder.
     *
     * @return \Closure
     */
    private function whereWrapper()
    {
        return function ($parameter)
        {
            if (!$parameter instanceof ParameterInterface && is_array($parameter))
            {
                $parameter = new Parameter($parameter);
            }

            if
            (
                $parameter instanceof SqlFragmentInterface
                && !$parameter instanceof ParameterInterface
                && !$parameter instanceof QueryBuilder
            )
            {
                return $parameter;
            }

            $this->whereParameters[] = $parameter;

            return $parameter;
        };
    }

    /**
     * Helper method used to convert complex where statement (specified by array, mongo like) to set
     * of where tokens. Method support simple expressions, nested, or and and groups and etc.
     *
     * Examples:
     * $select->where(["column" => 1]);
     *
     * $select->where(["column" => [">" => 1, "<" => 10]]);
     *
     * $select->where([
     *      "@or" => [
     *          ["id" => 1],
     *          ["column" => ["like" => "name"]]
     *      ]
     * ]);
     *
     * $select->where([
     *      '@or' => [
     *          ["id" => 1],
     *          ["id" => 2],
     *          ["id" => 3],
     *          ["id" => 4],
     *          ["id" => 5],
     *      ],
     *      "column" => [
     *          "like" => "name"
     *      ],
     *      'x'      => [
     *          '>' => 1,
     *          '<' => 10
     *      ]
     * ]);
     *
     * @param array    $where    Array of where conditions.
     * @param string   $grouping Parent grouping token (OR, AND)
     * @param array    $tokens   Array to aggregate compiled tokens.
     * @param \Closure $wrapper  Callback or closure used to handle all catched parameters, by
     *                           default $this->addParameter will be used.
     * @return array
     * @throws DatabaseException
     */
    protected function parseWhere(array $where, $grouping, &$tokens, callable $wrapper)
    {
        foreach ($where as $name => $value)
        {
            $tokenName = strtoupper($name);

            //Grouping identifier (@OR, @AND), Mongo like style
            if ($tokenName == self::TOKEN_AND || $tokenName == self::TOKEN_OR)
            {
                $tokens[] = [$grouping == self::TOKEN_AND ? 'AND' : 'OR', '('];

                foreach ($value as $subWhere)
                {
                    $this->parseWhere($subWhere, strtoupper($name), $tokens, $wrapper);
                }

                $tokens[] = ['', ')'];
                continue;
            }

            if (!is_array($value))
            {
                //Simple association
                $tokens[] = [
                    $grouping == self::TOKEN_AND ? 'AND' : 'OR',
                    [$name, '=', $wrapper($value)]
                ];
                continue;
            }

            $innerJoiner = $grouping == self::TOKEN_AND ? 'AND' : 'OR';
            if (count($value) > 1)
            {
                $tokens[] = [$grouping == self::TOKEN_AND ? 'AND' : 'OR', '('];
                $innerJoiner = 'AND';
            }

            foreach ($value as $key => $subValue)
            {
                if (is_numeric($key))
                {
                    throw new DatabaseException("Nested conditions should have defined operator.");
                }
                $key = strtoupper($key);
                if (in_array($key, ['BETWEEN', 'NOT BETWEEN']))
                {
                    if (!is_array($subValue) || count($subValue) != 2)
                    {
                        throw new DatabaseException(
                            "Exactly 2 array values required for between statement."
                        );
                    }

                    //One complex operation
                    $tokens[] = [
                        $innerJoiner,
                        [
                            $name,
                            $key,
                            $wrapper($subValue[0]),
                            $wrapper($subValue[1])
                        ]
                    ];
                }
                else
                {
                    //One complex operation
                    $tokens[] = [
                        $innerJoiner,
                        [$name, $key, $wrapper($subValue)]
                    ];
                }
            }

            if (count($value) > 1)
            {
                $tokens[] = ['', ')'];
            }
        }

        return $tokens;
    }
}