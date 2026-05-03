<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Model;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Data\Database;
use Rxn\Framework\Model\Record;

/**
 * Regression tests for Record::update() whitelist validation.
 *
 * The column map is injected via Reflection so the tests run
 * without any real database infrastructure.
 */
final class RecordUpdateTest extends TestCase
{
    /**
     * Build a concrete Record with injected column map, primary key,
     * and (optionally) a database stub — bypassing the constructor's
     * database round-trips entirely.
     *
     * @param  array<string, string> $columns    column_name => SQL type
     * @param  string                $primaryKey PK column name
     * @param  Database|null         $database   mocked Database (required only when
     *                                           the call is expected to reach the DB layer)
     */
    private function makeRecord(
        array    $columns,
        string   $primaryKey,
        ?Database $database = null,
    ): Record {
        $record = (new \ReflectionClass(StubRecord::class))->newInstanceWithoutConstructor();

        // Inject protected $columns (declared on Record)
        $columnsProp = (new \ReflectionClass(Record::class))->getProperty('columns');
        $columnsProp->setAccessible(true);
        $columnsProp->setValue($record, $columns);

        // Inject private $primary_key (declared on Record)
        $pkProp = (new \ReflectionClass(Record::class))->getProperty('primary_key');
        $pkProp->setAccessible(true);
        $pkProp->setValue($record, $primaryKey);

        // Inject protected $table (declared on Record; also set on StubRecord as default)
        $tableProp = (new \ReflectionClass(Record::class))->getProperty('table');
        $tableProp->setAccessible(true);
        $tableProp->setValue($record, 'stub_table');

        // Inject protected $database only when the caller provides one
        if ($database !== null) {
            $dbProp = (new \ReflectionClass(Record::class))->getProperty('database');
            $dbProp->setAccessible(true);
            $dbProp->setValue($record, $database);
        }

        return $record;
    }

    /**
     * Updating a real column (not the PK) must succeed and return the
     * record ID — the whitelist should pass column names, not SQL types.
     */
    public function testUpdateRealColumnSucceeds(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn(true);

        $record = $this->makeRecord(
            ['id' => 'int(11)', 'email' => 'varchar(255)', 'name' => 'varchar(100)'],
            'id',
            $db,
        );

        $result = $record->update(1, ['email' => 'new@example.com']);
        $this->assertSame(1, $result);
    }

    /**
     * Passing the primary key in an update payload must be rejected
     * with a 400-coded exception — the PK is excluded from the whitelist.
     *
     * Record::update() throws \Exception (no domain subclass exists in
     * the Model layer); we tighten the assertion with the message fragment.
     */
    public function testUpdatePrimaryKeyIsRejected(): void
    {
        $record = $this->makeRecord(
            ['id' => 'int(11)', 'email' => 'varchar(255)'],
            'id',
        );

        $this->expectException(\Exception::class);
        $this->expectExceptionCode(400);
        $this->expectExceptionMessageMatches('/forbidden field/i');
        $record->update(1, ['id' => 99]);
    }

    /**
     * A field that doesn't correspond to any table column must be
     * rejected with a 400-coded exception.
     *
     * Record::update() throws \Exception (no domain subclass exists in
     * the Model layer); we tighten the assertion with the message fragment.
     */
    public function testUpdateUnknownFieldIsRejected(): void
    {
        $record = $this->makeRecord(
            ['id' => 'int(11)', 'email' => 'varchar(255)'],
            'id',
        );

        $this->expectException(\Exception::class);
        $this->expectExceptionCode(400);
        $this->expectExceptionMessageMatches('/forbidden field/i');
        $record->update(1, ['unknown_field' => 'value']);
    }
}

/** Minimal concrete subclass — all update logic lives in Record. */
class StubRecord extends Record
{
    protected $table = 'stub_table';
}
