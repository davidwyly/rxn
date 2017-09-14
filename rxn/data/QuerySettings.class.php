<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author  David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn\Data;

use \Rxn\Config;
use \Rxn\Datasources;
use \Rxn\Utility\Debug as Debug;

/**
 * Class Facade
 *
 * @package Rxn\Data
 */
class QuerySettings
{
    const TYPE_QUERY       = 'query';
    const TYPE_FETCH       = 'fetch';
    const TYPE_FETCH_ALL   = 'fetchAll';
    const TYPE_FETCH_ARRAY = 'fetchArray';

    /**
     * @var string
     */
    public $raw_sql;

    /**
     * @var array
     */
    public $vars_to_prepare;

    /**
     * @var string
     */
    public $query_type;

    /**
     * @var bool
     */
    public $use_caching;

    /**
     * @var null|float
     */
    public $cache_timeout;

    public function __construct(
        string $raw_sql,
        array $vars_to_prepare = [],
        string $query_type = self::TYPE_QUERY,
        bool $use_caching = false,
        ?float $cache_timeout = null
    ) {
        $this->setRawSql($raw_sql);
        $this->setVarsToPrepare($vars_to_prepare);
        $this->setQueryType($query_type);
        $this->setUseCaching($use_caching);
        $this->setCacheTimeout($cache_timeout);
    }

    /**
     * @return string
     */
    public function getRawSql(): string
    {
        return $this->raw_sql;
    }

    /**
     * @param string $raw_sql
     */
    public function setRawSql(string $raw_sql)
    {
        $this->raw_sql = $raw_sql;
    }

    /**
     * @return array
     */
    public function getVarsToPrepare(): array
    {
        return $this->vars_to_prepare;
    }

    /**
     * @param array $vars_to_prepare
     */
    public function setVarsToPrepare(array $vars_to_prepare)
    {
        $this->vars_to_prepare = $vars_to_prepare;
    }

    /**
     * @return string
     */
    public function getQueryType(): string
    {
        return $this->query_type;
    }

    /**
     * @param string $query_type
     *
     * @throws \Exception
     */
    public function setQueryType(string $query_type)
    {
        $allowed_types = [
            self::TYPE_QUERY,
            self::TYPE_FETCH,
            self::TYPE_FETCH_ALL,
            self::TYPE_FETCH_ARRAY,
        ];
        if (!in_array($query_type, $allowed_types)) {
            throw new \Exception("Invalid query type '$query_type'");
        }
        $this->query_type = $query_type;
    }

    /**
     * @return bool
     */
    public function getUseCaching(): bool
    {
        return $this->use_caching;
    }

    /**
     * @param bool $use_caching
     */
    public function setUseCaching(bool $use_caching)
    {
        $this->use_caching = $use_caching;
    }

    /**
     * @return float|null
     */
    public function getCacheTimeout()
    {
        return $this->cache_timeout;
    }

    /**
     * @param float|null $cache_timeout
     */
    public function setCacheTimeout($cache_timeout)
    {
        $this->cache_timeout = $cache_timeout;
    }

}