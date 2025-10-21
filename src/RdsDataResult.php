<?php

declare(strict_types=1);

namespace Nemo64\DbalRdsData;

use Aws\Result as AwsResult;
use Doctrine\DBAL\Driver\Result;
use Iterator;
use IteratorAggregate;

use function array_column;
use function array_combine;
use function array_map;
use function count;
use function current;
use function is_array;
use function next;

class RdsDataResult implements IteratorAggregate, Result
{
    /**
     * @see https://docs.aws.amazon.com/aws-sdk-php/v3/api/api-rds-data-2018-08-01.html#executestatement
     */
    private AwsResult $result;

    private RdsDataConverter $dataConverter;

    public function __construct(AwsResult $result, RdsDataConverter|null $dataConverter = null)
    {
        $this->result = $result;
        $this->dataConverter = $dataConverter ?? new RdsDataConverter();
    }

    public function fetchNumeric(): array|false
    {
        $row = current($this->result['records']);
        if (! is_array($row)) {
            return false;
        }

        $result = array_map([$this->dataConverter, 'convertToValue'], $row);
        next($this->result['records']);

        return $result;
    }

    public function fetchAssociative(): array|false
    {
        $row = current($this->result['records']);
        if (! is_array($row)) {
            return false;
        }

        $numResult = array_map([$this->dataConverter, 'convertToValue'], $row);
        $columnNames = array_column($this->result['columnMetadata'], 'label');

        next($this->result['records']);

        return array_combine($columnNames, $numResult);
    }

    public function fetchOne(): mixed
    {
        $row = $this->fetchNumeric();
        if ($row === false) {
            return false;
        }

        return $row[0] ?? false;
    }

    public function fetchAllNumeric(): array
    {
        $rows = [];
        while (($row = $this->fetchNumeric()) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }

    public function fetchAllAssociative(): array
    {
        $rows = [];
        while (($row = $this->fetchAssociative()) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }

    public function fetchFirstColumn(): array
    {
        $rows = [];
        while (($value = $this->fetchOne()) !== false) {
            $rows[] = $value;
        }

        return $rows;
    }

    public function columnCount(): int
    {
        return count($this->result['columnMetadata']);
    }

    /**
     * @return Iterator
     */
    public function getIterator(): Iterator
    {
        while (($row = $this->fetchAssociative()) !== false) {
            yield $row;
        }
    }

    public function free(): void
    {
        if (isset($this->result['records'])) {
            $this->result['records'] = null;
        }
    }

    /**
     * @see \Doctrine\DBAL\Driver\Statement::rowCount
     */
    public function rowCount(): int
    {
        if (isset($this->result['numberOfRecordsUpdated'])) {
            return $this->result['numberOfRecordsUpdated'];
        }

        if (isset($this->result['records'])) {
            return count($this->result['records']);
        }

        return 0;
    }
}
