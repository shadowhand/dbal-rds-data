<?php

declare(strict_types=1);

namespace Nemo64\DbalRdsData\Tests;

use Doctrine\DBAL\Driver\Result;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function base64_encode;
use function sprintf;

class RdsDataConnectionTest extends TestCase
{
    use RdsDataServiceClientTrait;

    protected function setUp(): void
    {
        $this->createRdsDataServiceClient();
    }

    public function testSimpleQuery(): void
    {
        $this->addClientCall(
            'executeStatement',
            [
                'resourceArn' => 'arn:resource',
                'secretArn' => 'arn:secret',
                'database' => 'db',
                'continueAfterTimeout' => false,
                'includeResultMetadata' => true,
                'parameters' => [],
                'resultSetOptions' => ['decimalReturnType' => 'STRING'],
                'sql' => 'SELECT * FROM table',
            ],
            [
                'columnMetadata' => [
                    ['label' => 'id'],
                ],
                'numberOfRecordsUpdated' => 0,
                'records' => [
                    [
                        ['longValue' => 1],
                    ],
                ],
            ],
        );

        $result = $this->connection->query('SELECT * FROM table');
        $this->assertEquals(['id' => 1], $result->fetchAssociative());
        $this->assertFalse($result->fetchAssociative());
    }

    public function testTransaction(): void
    {
        $this->addClientCall(
            'beginTransaction',
            [
                'resourceArn' => 'arn:resource',
                'secretArn' => 'arn:secret',
                'database' => 'db',
            ],
            ['transactionId' => '~~transaction id~~'],
        );
        $this->assertTrue($this->connection->beginTransaction());
        $this->assertEquals('~~transaction id~~', $this->connection->getTransactionId());

        $this->addClientCall(
            'executeStatement',
            [
                'resourceArn' => 'arn:resource',
                'secretArn' => 'arn:secret',
                'database' => 'db',
                'continueAfterTimeout' => false,
                'includeResultMetadata' => true,
                'parameters' => [],
                'resultSetOptions' => ['decimalReturnType' => 'STRING'],
                'sql' => 'SELECT * FROM table',
                'transactionId' => $this->connection->getTransactionId(),
            ],
            [
                'columnMetadata' => [
                    ['label' => 'id'],
                ],
                'numberOfRecordsUpdated' => 0,
                'records' => [
                    [
                        ['longValue' => 1],
                    ],
                ],
            ],
        );
        $result = $this->connection->query('SELECT * FROM table');
        $this->assertEquals(['id' => 1], $result->fetchAssociative());
        $this->assertFalse($result->fetchAssociative());

        $this->assertFalse($this->connection->beginTransaction());

        $this->addClientCall(
            'commitTransaction',
            [
                'resourceArn' => 'arn:resource',
                'secretArn' => 'arn:secret',
                'transactionId' => '~~transaction id~~',
            ],
            ['transactionStatus' => 'cleaning up'],
        );
        $this->assertTrue($this->connection->commit());
        $this->assertFalse($this->connection->commit());
        $this->assertFalse($this->connection->rollBack());
    }

    public function testRollBack(): void
    {
        $this->addClientCall(
            'beginTransaction',
            [
                'resourceArn' => 'arn:resource',
                'secretArn' => 'arn:secret',
                'database' => 'db',
            ],
            ['transactionId' => '~~transaction id~~'],
        );
        $this->assertTrue($this->connection->beginTransaction());
        $this->assertEquals('~~transaction id~~', $this->connection->getTransactionId());
        $this->assertFalse($this->connection->beginTransaction());

        $this->addClientCall(
            'rollbackTransaction',
            [
                'resourceArn' => 'arn:resource',
                'secretArn' => 'arn:secret',
                'transactionId' => '~~transaction id~~',
            ],
            ['transactionStatus' => 'cleaning up'],
        );
        $this->assertTrue($this->connection->rollBack());
        $this->assertFalse($this->connection->rollBack());
        $this->assertFalse($this->connection->commit());
    }

    public function testUpdate(): void
    {
        $this->addClientCall(
            'executeStatement',
            [
                'resourceArn' => 'arn:resource',
                'secretArn' => 'arn:secret',
                'database' => 'db',
                'continueAfterTimeout' => false,
                'includeResultMetadata' => true,
                'parameters' => [],
                'resultSetOptions' => ['decimalReturnType' => 'STRING'],
                'sql' => 'UPDATE foobar SET value = 1',
            ],
            [
                'numberOfRecordsUpdated' => 5,
            ],
        );

        $rowCount = $this->connection->exec('UPDATE foobar SET value = 1');
        $this->assertEquals(5, $rowCount);
    }

    public function testParameters(): void
    {
        $this->addClientCall(
            'executeStatement',
            [
                'resourceArn' => 'arn:resource',
                'secretArn' => 'arn:secret',
                'database' => 'db',
                'continueAfterTimeout' => false,
                'includeResultMetadata' => true,
                'parameters' => [
                    ['name' => '0', 'value' => ['stringValue' => 5]],
                ],
                'resultSetOptions' => ['decimalReturnType' => 'STRING'],
                'sql' => 'UPDATE foobar SET value = :0',
            ],
            [
                'numberOfRecordsUpdated' => 5,
            ],
        );

        $statement = $this->connection->prepare('UPDATE foobar SET value = ?');
        $statement->bindValue(0, 5);
        $result = $statement->execute();
        $this->assertEquals(5, $result->rowCount());
    }

    public static function quoteValues(): array
    {
        return [
            ['foobar', "'foobar'"],
            ['foo\'bar', "'foo\\'bar'"],
            ['äöü', sprintf("FROM_BASE64('%s')", base64_encode('äöü'))],
        ];
    }

    #[DataProvider('quoteValues')]
    public function testQuote(string $value, string $expectation): void
    {
        $this->assertEquals($expectation, $this->connection->quote($value));
    }

    public function testInsert(): void
    {
        $this->addClientCall(
            'executeStatement',
            [
                'resourceArn' => 'arn:resource',
                'secretArn' => 'arn:secret',
                'database' => 'db',
                'continueAfterTimeout' => false,
                'includeResultMetadata' => true,
                'parameters' => [
                    ['name' => '0', 'value' => ['stringValue' => 5]],
                ],
                'resultSetOptions' => ['decimalReturnType' => 'STRING'],
                'sql' => 'INSERT INTO foobar SET value = :0',
            ],
            [
                'numberOfRecordsUpdated' => 1,
                'generatedFields' => [
                    ['longValue' => 5],
                ],
            ],
        );

        $statement = $this->connection->prepare('INSERT INTO foobar SET value = ?');
        $result = $statement->execute([5]);
        $this->assertEquals(1, $result->rowCount());
        $this->assertEquals(5, $this->connection->lastInsertId());
    }

    public static function databaseUseStatements(): array
    {
        return [
            ['foobar', 'use foobar'],
            ['foobar', 'use foobar;'],
            ['foobar', 'use   foobar ; '],
            ['bar foo', 'use `bar foo`'],
            ['bar foo', 'use `bar foo`;'],
            ['bar foo', 'use   `bar foo`  ;  '],
        ];
    }

    #[DataProvider('databaseUseStatements')]
    public function testUseDatabase(string $dbname, string $useStatement): void
    {
        $this->assertEquals('db', $this->connection->getDatabase());
        $statement = $this->connection->prepare($useStatement);
        $this->assertEquals('db', $this->connection->getDatabase());
        $result = $statement->execute();
        $this->assertInstanceOf(Result::class, $result);
        $this->assertEquals($dbname, $this->connection->getDatabase());
    }
}
