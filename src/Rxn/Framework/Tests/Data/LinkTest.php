<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Data;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Data\Map\Chain\Link;

final class LinkTest extends TestCase
{
    public function testAccessorsExposeTheEdge(): void
    {
        $link = new Link('orders', 'user_id', 'users', 'id');

        $this->assertSame('orders', $link->fromTable);
        $this->assertSame('user_id', $link->fromColumn);
        $this->assertSame('users', $link->toTable);
        $this->assertSame('id', $link->toColumn);
    }

    public function testSignatureIsStableAndDistinguishesDirection(): void
    {
        $a = new Link('orders', 'user_id', 'users', 'id');
        $b = new Link('orders', 'user_id', 'users', 'id');
        $c = new Link('users', 'id', 'orders', 'user_id');

        $this->assertSame($a->signature(), $b->signature());
        $this->assertNotSame($a->signature(), $c->signature());
        $this->assertSame('orders.user_id->users.id', $a->signature());
    }

    public function testEmptyComponentsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Link('', 'col', 'to', 'id');
    }
}
