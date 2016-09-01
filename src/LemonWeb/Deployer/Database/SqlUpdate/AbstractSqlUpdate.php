<?php

namespace LemonWeb\Deployer\Database\SqlUpdate;

abstract class AbstractSqlUpdate implements SqlUpdateInterface
{
    /**
     * @var bool
     */
    protected $active = true;

    /**
     * @var int
     */
    protected $type = SqlUpdateInterface::TYPE_SMALL;

    /**
     * @var array
     */
    protected $dependencies = array();

    /**
     * @var array
     */
    protected $options = array();

    /**
     * {@inheritdoc}
     */
    public function __construct(array $options)
    {
        $this->options = array_merge(array(
            'charset' => 'utf8',
        ), $options);
    }

    /**
     * {@inheritdoc}
     */
    public function down()
    {
        return '';
    }

    /**
     * {@inheritdoc}
     */
    final public function isActive()
    {
        return $this->active;
    }

    /**
     * {@inheritdoc}
     */
    final public function getType()
    {
        return $this->type;
    }

    /**
     * {@inheritdoc}
     */
    final public function getDependencies()
    {
        if (get_class($this) != 'sql_19700101_000000_dbpatcher') {
            return array_merge(array('sql_19700101_000000_dbpatcher'), $this->dependencies);
        }

        return $this->dependencies;
    }
}
