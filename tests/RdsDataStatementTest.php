<?php

declare(strict_types=1);

namespace Nemo64\DbalRdsData\Tests;

use Nemo64\DbalRdsData\RdsDataStatement;
use PHPUnit\Framework\TestCase;

use function range;

class RdsDataStatementTest extends TestCase
{
    use RdsDataServiceClientTrait;

    public function setUp(): void
    {
        $this->createRdsDataServiceClient();
    }

    public function testRetainFetchMode(): void
    {
        foreach (range(1, 2) as $item) {
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
                    'sql' => 'SELECT 1 AS id',
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
        }

        $statement = new RdsDataStatement($this->connection, 'SELECT 1 AS id');

        $result1 = $statement->execute();
        $this->assertEquals([[1]], $result1->fetchAllNumeric());
        $result2 = $statement->execute();
        $this->assertEquals([[1]], $result2->fetchAllNumeric());
    }
}
