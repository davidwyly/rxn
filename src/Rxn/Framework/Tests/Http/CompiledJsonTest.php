<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Http\CompiledJson;

final class CompiledJsonTest extends TestCase
{
    public function testScalarMix(): void
    {
        $dto = new class {
            public int    $id     = 42;
            public string $name   = 'Widget';
            public float  $price  = 9.99;
            public bool   $active = true;
        };
        $encoder = CompiledJson::for($dto::class);
        $this->assertSame(
            '{"id":42,"name":"Widget","price":9.99,"active":true}',
            $encoder($dto)
        );
    }

    public function testNullableHandled(): void
    {
        $dto = new class {
            public ?int  $id     = null;
            public ?bool $active = false;
        };
        $encoder = CompiledJson::for($dto::class);
        $this->assertSame('{"id":null,"active":false}', $encoder($dto));
    }

    public function testStringEscapingMatchesJsonEncode(): void
    {
        $dto = new class {
            public string $title = "He said \"hi\"\nthen left";
        };
        $encoder = CompiledJson::for($dto::class);
        $this->assertSame(
            json_encode(['title' => $dto->title], JSON_UNESCAPED_SLASHES),
            $encoder($dto)
        );
    }

    public function testEmptyClass(): void
    {
        $dto = new class {};
        $encoder = CompiledJson::for($dto::class);
        $this->assertSame('{}', $encoder($dto));
    }

    public function testArrayProperty(): void
    {
        $dto = new class {
            /** @var int[] */
            public array $tags = [1, 2, 3];
        };
        $encoder = CompiledJson::for($dto::class);
        $this->assertSame('{"tags":[1,2,3]}', $encoder($dto));
    }

    public function testStaticPropertiesIgnored(): void
    {
        $dto = new class {
            public static int $version = 7;
            public int $id = 1;
        };
        $encoder = CompiledJson::for($dto::class);
        $this->assertSame('{"id":1}', $encoder($dto));
    }

    public function testConvenienceEncode(): void
    {
        $dto = new class {
            public int $n = 1;
        };
        $this->assertSame('{"n":1}', CompiledJson::encode($dto));
    }

    public function testEncoderIsCachedPerClass(): void
    {
        $dto = new class {
            public int $n = 1;
        };
        $a = CompiledJson::for($dto::class);
        $b = CompiledJson::for($dto::class);
        $this->assertSame($a, $b);
    }
}
