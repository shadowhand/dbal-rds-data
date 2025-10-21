<?php

declare(strict_types=1);

namespace Nemo64\DbalRdsData\Tests;

use Aws\RDSDataService\RDSDataServiceClient;
use Aws\Result;
use Nemo64\DbalRdsData\RdsDataConnection;
use PHPUnit\Framework\MockObject\MockObject;

use function array_shift;

trait RdsDataServiceClientTrait
{
    private MockObject|RDSDataServiceClient $client;

    private array $expectedCalls = [
        // ['executeStatement', ['options' => 'value'], 'returnValue']
    ];

    private RdsDataConnection $connection;

    protected function createRdsDataServiceClient(): void
    {
        $this->client = $this->createMock(RDSDataServiceClient::class);
        $this->client->method('__call')->willReturnCallback(function (string $methodName, array $arguments) {
            $nextCall = array_shift($this->expectedCalls);
            $this->assertIsArray($nextCall, 'there must be another call planned');
            $this->assertEquals($nextCall[0], $methodName, 'method call');
            $this->assertEquals($nextCall[1], $arguments[0], "options of $methodName");

            return $nextCall[2];
        });

        $this->connection = new RdsDataConnection($this->client, 'arn:resource', 'arn:secret', 'db');
    }

    private function addClientCall(string $method, array $options, array $result): void
    {
        $this->expectedCalls[] = [$method, $options, new Result($result)];
    }
}
