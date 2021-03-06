<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 */
namespace Spiral\Database\Drivers\SQLServer\Schemas;

use Spiral\Database\Entities\Driver;
use Spiral\Database\Entities\Schemas\AbstractColumn;

/**
 * SQL Server specific column schema.
 */
class ColumnSchema extends AbstractColumn
{
    /**
     * {@inheritdoc}
     */
    protected $mapping = [
        //Primary sequences
        'primary'     => [
            'type'     => 'int',
            'identity' => true,
            'nullable' => false
        ],
        'bigPrimary'  => [
            'type'     => 'bigint',
            'identity' => true,
            'nullable' => false
        ],
        //Enum type (mapped via method)
        'enum'        => 'enum',
        //Logical types
        'boolean'     => 'bit',
        //Integer types (size can always be changed with size method), longInteger has method alias
        //bigInteger
        'integer'     => 'int',
        'tinyInteger' => 'tinyint',
        'bigInteger'  => 'bigint',
        //String with specified length (mapped via method)
        'string'      => 'varchar',
        //Generic types
        'text'        => ['type' => 'varchar', 'size' => 0],
        'tinyText'    => ['type' => 'varchar', 'size' => 0],
        'longText'    => ['type' => 'varchar', 'size' => 0],
        //Real types
        'double'      => 'float',
        'float'       => 'real',
        //Decimal type (mapped via method)
        'decimal'     => 'decimal',
        //Date and Time types
        'datetime'    => 'datetime',
        'date'        => 'date',
        'time'        => 'time',
        'timestamp'   => 'datetime',
        //Binary types
        'binary'      => ['type' => 'varbinary', 'size' => 0],
        'tinyBinary'  => ['type' => 'varbinary', 'size' => 0],
        'longBinary'  => ['type' => 'varbinary', 'size' => 0],
        //Additional types
        'json'        => ['type' => 'varchar', 'size' => 0]
    ];

    /**
     * {@inheritdoc}
     */
    protected $reverseMapping = [
        'primary'     => [['type' => 'int', 'identity' => true]],
        'bigPrimary'  => [['type' => 'bigint', 'identity' => true]],
        'enum'        => ['enum'],
        'boolean'     => ['bit'],
        'integer'     => ['int'],
        'tinyInteger' => ['tinyint', 'smallint'],
        'bigInteger'  => ['bigint'],
        'text'        => [['type' => 'varchar', 'size' => 0]],
        'string'      => ['varchar', 'char'],
        'double'      => ['float'],
        'float'       => ['real'],
        'decimal'     => ['decimal'],
        'timestamp'   => ['datetime'],
        'date'        => ['date'],
        'time'        => ['time'],
        'binary'      => ['varbinary'],
    ];

    /**
     * Field is table identity.
     *
     * @var bool
     */
    protected $identity = false;

    /**
     * Name of default constraint.
     *
     * @var string
     */
    protected $defaultConstraint = '';

    /**
     * Name of enum constraint.
     *
     * @var string
     */
    protected $enumConstraint = '';

    /**
     * {@inheritdoc}
     */
    public function getConstraints()
    {
        $constraints = parent::getConstraints();

        if (!empty($this->defaultConstraint)) {
            $constraints[] = $this->defaultConstraint;
        }

        if (!empty($this->enumConstraint)) {
            $constraints[] = $this->enumConstraint;
        }

        return $constraints;
    }

    /**
     * {@inheritdoc}
     */
    public function abstractType()
    {
        if (!empty($this->enumValues)) {
            return 'enum';
        }

        return parent::abstractType();
    }


    /**
     * {@inheritdoc}
     */
    public function enum($values)
    {
        $this->enumValues = array_map('strval', is_array($values) ? $values : func_get_args());
        sort($this->enumValues);

        $this->type = 'varchar';
        foreach ($this->enumValues as $value) {
            $this->size = max((int)$this->size, strlen($value));
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @param bool $enum If true ENUM declaration statement will be returned only. Internal helper.
     */
    public function sqlStatement($enum = false)
    {
        if ($enum || $this->abstractType() != 'enum') {
            $statement = [$this->getName(true), $this->type];

            if (!empty($this->precision)) {
                $statement[] = "({$this->precision}, {$this->scale})";
            } elseif (!empty($this->size)) {
                $statement[] = "({$this->size})";
            } elseif ($this->type == 'varchar' || $this->type == 'varbinary') {
                $statement[] = "(max)";
            }

            if ($this->identity) {
                $statement[] = 'IDENTITY(1,1)';
            }

            $statement[] = $this->nullable ? 'NULL' : 'NOT NULL';

            if ($this->hasDefaultValue()) {
                $statement[] = "DEFAULT {$this->prepareDefault()}";
            }

            return join(' ', $statement);
        }

        //We have add constraint for enum type
        $enumValues = [];
        foreach ($this->enumValues as $value) {
            $enumValues[] = $this->table->driver()->getPDO()->quote($value);
        }

        $statement = $this->sqlStatement(true);

        return "$statement CONSTRAINT {$this->enumConstraint(true, true)} "
        . "CHECK ({$this->getName(true)} IN (" . join(', ', $enumValues) . "))";
    }

    /**
     * Generate set of altering operations should be applied to column to change it's type, size,
     * default value or null flag.
     *
     * @param ColumnSchema $original
     * @return array
     */
    public function alterOperations(ColumnSchema $original)
    {
        $operations = [];

        $typeDefinition = [
            $this->type,
            $this->size,
            $this->precision,
            $this->scale,
            $this->nullable
        ];

        $originalType = [
            $original->type,
            $original->size,
            $original->precision,
            $original->scale,
            $original->nullable
        ];

        if ($typeDefinition != $originalType) {
            if ($this->abstractType() == 'enum') {
                //Getting longest value
                $enumSize = $this->size;
                foreach ($this->enumValues as $value) {
                    $enumSize = max($enumSize, strlen($value));
                }

                $type = "ALTER COLUMN {$this->getName(true)} varchar($enumSize)";
                $operations[] = $type . ' ' . ($this->nullable ? 'NULL' : 'NOT NULL');
            } else {
                $type = "ALTER COLUMN {$this->getName(true)} {$this->type}";

                if (!empty($this->size)) {
                    $type .= "($this->size)";
                } elseif ($this->type == 'varchar' || $this->type == 'varbinary') {
                    $type .= "(max)";
                } elseif (!empty($this->precision)) {
                    $type .= "($this->precision, $this->scale)";
                }

                $operations[] = $type . ' ' . ($this->nullable ? 'NULL' : 'NOT NULL');
            }
        }

        //Constraint should be already removed it this moment (see doColumnChange in TableSchema)
        if ($this->hasDefaultValue()) {
            if (!$this->defaultConstraint) {
                //Making new name
                $this->defaultConstraint = $this->table->getName() . '_'
                    . $this->getName() . '_default_' . uniqid();
            }

            $operations[] = \Spiral\interpolate(
                "ADD CONSTRAINT {constraint} DEFAULT {default} FOR {column}",
                [
                    'constraint' => $this->table->driver()->identifier($this->defaultConstraint),
                    'column'     => $this->getName(true),
                    'default'    => $this->prepareDefault()
                ]
            );
        }

        //Constraint should be already removed it this moment (see doColumnChange in TableSchema)
        if ($this->abstractType() == 'enum') {
            $enumValues = [];
            foreach ($this->enumValues as $value) {
                $enumValues[] = $this->table->driver()->getPDO()->quote($value);
            }

            $operations[] = "ADD CONSTRAINT {$this->enumConstraint(true)} "
                . "CHECK ({$this->getName(true)} IN (" . join(', ', $enumValues) . "))";
        }

        return $operations;
    }

    /**
     * {@inheritdoc}
     */
    protected function resolveSchema($schema)
    {
        $this->type = $schema['DATA_TYPE'];
        $this->nullable = strtoupper($schema['IS_NULLABLE']) == 'YES';
        $this->defaultValue = $schema['COLUMN_DEFAULT'];

        $this->identity = (bool)$schema['is_identity'];

        $this->size = (int)$schema['CHARACTER_MAXIMUM_LENGTH'];
        if ($this->size == -1) {
            $this->size = 0;
        }

        if ($this->type == 'decimal') {
            $this->precision = (int)$schema['NUMERIC_PRECISION'];
            $this->scale = (int)$schema['NUMERIC_SCALE'];
        }

        //Normalizing default value
        $this->normalizeDefault();

        /**
         * We have to fetch all column constrains cos default and enum check will be included into
         * them, plus column drop is not possible without removing all constraints.
         */

        $tableDriver = $this->table->driver();
        if (!empty($schema['default_object_id'])) {
            $this->defaultConstraint = $tableDriver->query(
                "SELECT name FROM sys.default_constraints WHERE object_id = ?", [
                $schema['default_object_id']
            ])->fetchColumn();
        }

        //Potential enum
        if ($this->type == 'varchar' && !empty($this->size)) {
            $this->resolveEnum($schema, $tableDriver);
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function prepareDefault()
    {
        $defaultValue = parent::prepareDefault();
        if ($this->abstractType() == 'boolean') {
            $defaultValue = (int)$this->defaultValue;
        }

        return $defaultValue;
    }

    /**
     * Get name of enum constraint.
     *
     * @param bool $quote     True to quote identifier.
     * @param bool $temporary If true enumConstraint identifier will be generated only for visual
     *                        purposes.
     * @return string
     */
    protected function enumConstraint($quote = false, $temporary = false)
    {
        if (empty($this->enumConstraint)) {
            if ($temporary) {
                return $this->table->getName() . '_' . $this->getName() . '_enum';
            }

            $this->enumConstraint = $this->table->getName() . '_'
                . $this->getName() . '_enum_' . uniqid();
        }

        return $quote
            ? $this->table->driver()->identifier($this->enumConstraint)
            : $this->enumConstraint;
    }

    /**
     * Normalizing default value.
     */
    private function normalizeDefault()
    {
        if ($this->defaultValue[0] == '(' && $this->defaultValue[strlen($this->defaultValue) - 1] == ')') {
            $this->defaultValue = substr($this->defaultValue, 1, -1);
        }

        if (preg_match('/^[\'""].*?[\'"]$/', $this->defaultValue)) {
            $this->defaultValue = substr($this->defaultValue, 1, -1);
        }

        if (
            $this->phpType() != 'string'
            && ($this->defaultValue[0] == '(' && $this->defaultValue[strlen($this->defaultValue) - 1] == ')')
        ) {
            $this->defaultValue = substr($this->defaultValue, 1, -1);
        }
    }

    /**
     * Check if column is enum.
     *
     * @param array  $schema
     * @param Driver $tableDriver
     */
    private function resolveEnum(array $schema, $tableDriver)
    {
        $query = "SELECT object_definition(o.object_id) AS [definition],
                             OBJECT_NAME(o.OBJECT_ID) AS [name]
                      FROM sys.objects AS o
                      JOIN sys.sysconstraints AS [c]
                        ON o.object_id = [c].constid
                      WHERE type_desc = 'CHECK_CONSTRAINT' AND parent_object_id = ? AND [c].colid = ?";

        $constraints = $tableDriver->query($query, [
            $schema['object_id'],
            $schema['column_id']
        ]);

        foreach ($constraints as $checkConstraint) {
            $this->enumConstraint = $checkConstraint['name'];

            $name = preg_quote($this->getName(true));

            //We made some assumptions here...
            if (preg_match_all(
                '/' . $name . '=[\']?([^\']+)[\']?/i',
                $checkConstraint['definition'],
                $matches
            )) {
                $this->enumValues = $matches[1];
                sort($this->enumValues);
            }
        }
    }
}