<?php

declare(strict_types=1);

namespace Nemo64\DbalRdsData\Tests;

use Aws\Result;
use Nemo64\DbalRdsData\RdsDataResult;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;

class RdsDataResultTest extends TestCase
{
    private RdsDataResult $result;

    protected function setUp(): void
    {
        $this->result = new RdsDataResult(new Result([
            'columnMetadata' => [
                ['label' => 'col1'],
                ['label' => 'col2'],
            ],
            'records' => [
                [
                    ['stringValue' => 'foo'],
                    ['stringValue' => 'bar'],
                ],
            ],
        ]));
    }

    public function testFetchAssociative(): void
    {
        $this->assertEquals(['col1' => 'foo', 'col2' => 'bar'], $this->result->fetchAssociative());
        $this->assertEquals(false, $this->result->fetchAssociative());
    }

    public function testFetchNumeric(): void
    {
        $this->assertEquals(['foo', 'bar'], $this->result->fetchNumeric());
        $this->assertEquals(false, $this->result->fetchNumeric());
    }

    public function testFetchOne(): void
    {
        $this->assertEquals('foo', $this->result->fetchOne());
        $this->assertEquals(false, $this->result->fetchOne());
    }

    public function testFetchAllAssociative(): void
    {
        $this->assertEquals(
            [['col1' => 'foo', 'col2' => 'bar']],
            $this->result->fetchAllAssociative(),
        );
    }

    public function testFetchAllNumeric(): void
    {
        $this->assertEquals(
            [['foo', 'bar']],
            $this->result->fetchAllNumeric(),
        );
    }

    public function testFetchFirstColumn(): void
    {
        $this->assertEquals(['foo'], $this->result->fetchFirstColumn());
    }

    public function testIterator(): void
    {
        $this->assertEquals(
            [['col1' => 'foo', 'col2' => 'bar']],
            iterator_to_array($this->result->getIterator()),
        );
    }

    public function testColumnCount(): void
    {
        $this->assertEquals(2, $this->result->columnCount());
    }

    public function testRowCount(): void
    {
        $this->assertEquals(1, $this->result->rowCount());
    }
}
