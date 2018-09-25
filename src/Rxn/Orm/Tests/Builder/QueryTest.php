<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Builder;

use PHPUnit\Framework\TestCase;
use Rxn\Orm\Builder\Query;

final class QueryTest extends TestCase
{
    public function __after()
    {

    }

    public function testSelectStar()
    {
        /**
         * "['*']" resolves as "*"
         */
        $query = new Query();
        $query->select(['*']);
        $this->assertEquals('*', $query->commands['SELECT'][0]);

        /**
         * empty array resolves as "*"
         */
        $query = new Query();
        $query->select([]);
        $this->assertEquals('*', $query->commands['SELECT'][0]);

        /**
         * nothing passed resolves as "*"
         */
        $query = new Query();
        $query->select();
        $this->assertEquals('*', $query->commands['SELECT'][0]);
    }

    public function testSelectAssociative()
    {
        /**
         * escape column and alias
         */
        $query = new Query();
        $query->select(['id' => 'user_id']);
        $this->assertEquals('`id` AS `user_id`', $query->commands['SELECT'][0]);

        /**
         * escape table, column, and alias
         */
        $query = new Query();
        $query->select(['user.id' => 'user_id']);
        $this->assertEquals('`user`.`id` AS `user_id`', $query->commands['SELECT'][0]);

        /**
         * test with null value as alias
         */
        $query = new Query();
        $query->select(['user.id' => null]);
        $this->assertEquals('`user`.`id`', $query->commands['SELECT'][0]);

        /**
         * test multiple elements in the array
         * with table and column
         */
        $query = new Query();
        $query->select([
            'user.id'  => 'user_id',
            'order.id' => 'order_id',
        ]);
        $this->assertEquals('`user`.`id` AS `user_id`', $query->commands['SELECT'][0]);
        $this->assertEquals('`order`.`id` AS `order_id`', $query->commands['SELECT'][1]);

        /**
         * test multiple elements in the array
         * with table and column
         * with accidental whitespace
         * with incorrect back-tics
         * with null value as alias
         */
        $query = new Query();
        $query->select([
            '`user `.id  '  => '`user_id``',
            '`order.id` ' => ' `order_id`',
            '`name` ' => null,
        ]);
        $this->assertEquals('`user`.`id` AS `user_id`', $query->commands['SELECT'][0]);
        $this->assertEquals('`order`.`id` AS `order_id`', $query->commands['SELECT'][1]);
        $this->assertEquals('`name`', $query->commands['SELECT'][2]);
    }

    public function testSelect()
    {
        /**
         * test a single element
         */
        $query = new Query();
        $query->select(['id']);
        $this->assertEquals('`id`', $query->commands['SELECT'][0]);

        /**
         * test with accidental whitespace
         * with single element
         */
        $query = new Query();
        $query->select([' id ']);
        $this->assertEquals('`id`', $query->commands['SELECT'][0]);

        /**
         * test with with back-tics
         * with single element
         */
        $query = new Query();
        $query->select(['`id`']);
        $this->assertEquals('`id`', $query->commands['SELECT'][0]);

        /**
         * test with multiple elements
         */
        $query = new Query();
        $query->select([
            'id',
            'name'
        ]);
        $this->assertEquals('`id`', $query->commands['SELECT'][0]);

        /**
         * test with multiple elements
         * with table and column
         */
        $query = new Query();
        $query->select([
            'user.id',
            'order.id'
        ]);
        $this->assertEquals('`user`.`id`', $query->commands['SELECT'][0]);
        $this->assertEquals('`order`.`id`', $query->commands['SELECT'][1]);

        /**
         * test with alias in the string
         * with single element
         */
        $query = new Query();
        $query->select(['id AS user_id']);
        $this->assertEquals('`id` AS `user_id`', $query->commands['SELECT'][0]);

        /**
         * test with table and column
         * with alias in the string
         * with single element
         */
        $query = new Query();
        $query->select(['user.id AS user_id']);
        $this->assertEquals('`user`.`id` AS `user_id`', $query->commands['SELECT'][0]);

        /**
         * test with comma-delimited clauses
         * with alias in the string
         * with table and column
         * with accidental whitespace
         * with incorrect back-tics
         */
        $query = new Query();
        $query->select(['`user.id` AS   user_id , order.id AS `order_id`']);
        $this->assertEquals('`user`.`id` AS `user_id`', $query->commands['SELECT'][0]);
        $this->assertEquals('`order`.`id` AS `order_id`', $query->commands['SELECT'][1]);

        /**
         * test with comma-delimited clauses
         * with some aliases and no aliases
         * with table and column
         * with accidental whitespace
         * with incorrect back-tics
         */
        $query = new Query();
        $query->select(['`user.id` AS   user_id , `order.`id `']);
        $this->assertEquals('`user`.`id` AS `user_id`', $query->commands['SELECT'][0]);
        $this->assertEquals('`order`.`id`', $query->commands['SELECT'][1]);
    }
}
