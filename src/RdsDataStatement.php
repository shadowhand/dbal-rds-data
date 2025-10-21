<?php

declare(strict_types=1);

namespace Nemo64\DbalRdsData;

use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\FetchMode;
use Doctrine\DBAL\ParameterType;
use Iterator;
use IteratorAggregate;
use PDO;

use function func_get_args;
use function is_iterable;
use function preg_match;
use function reset;

/**
 * @see https://docs.aws.amazon.com/aws-sdk-php/v3/api/api-rds-data-2018-08-01.html
 * @see https://docs.aws.amazon.com/AmazonRDS/latest/AuroraUserGuide/data-api.html
 * @see https://docs.aws.amazon.com/rdsdataservice/latest/APIReference/API_ExecuteStatement.html
 */
class RdsDataStatement implements IteratorAggregate, Statement
{
    /**
     * This expression is used to detect DDL queries.
     * Basically queries that modify the schema and can't be executed in a transaction.
     *
     * @see https://en.wikipedia.org/wiki/Data_definition_language
     */
    private const string DDL_REGEX = '#^\s*(CREATE|DROP|ALTER|TRUNCATE)\s+(TABLE|INDEX|VIEW)#Si';

    private RdsDataConverter $dataConverter;

    private RdsDataParameterBag $parameterBag;

    /**
     * Retain the fetch mode across results
     */
    private array $fetchMode = [FetchMode::MIXED];

    private RdsDataResult|null $result = null;

    public function __construct(private RdsDataConnection $connection, private string $sql, RdsDataConverter|null $dataConverter = null)
    {
        $this->dataConverter = $dataConverter ?? new RdsDataConverter();
        $this->parameterBag = new RdsDataParameterBag($this->dataConverter);
    }

    public function closeCursor(): bool
    {
        // there is not really a cursor but I can free the memory the records are taking up.
        $this->result = null;

        return true;
    }

    public function columnCount(): int
    {
        return $this->result->columnCount();
    }

    /**
     * @inheritDoc
     */
    public function setFetchMode($fetchMode, $arg2 = null, $arg3 = null): bool
    {
        $this->fetchMode = func_get_args();

        if ($this->result !== null) {
            $this->result->setFetchMode(...$this->fetchMode);
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function fetchAll($fetchMode = null, $fetchArgument = null, $ctorArgs = null): array
    {
        return $this->result->fetchAll($fetchMode, $fetchArgument, $ctorArgs);
    }

    /**
     * @inheritDoc
     */
    public function fetchColumn($columnIndex = 0): mixed
    {
        return $this->result->fetchColumn($columnIndex);
    }

    /**
     * @inheritDoc
     */
    public function fetch($fetchMode = null, $cursorOrientation = PDO::FETCH_ORI_NEXT, $cursorOffset = 0): mixed
    {
        return $this->result->fetch($fetchMode, $cursorOrientation, $cursorOffset);
    }

    /**
     * @inheritDoc
     */
    public function bindParam($param, &$variable, $type = ParameterType::STRING, $length = null): bool
    {
        return $this->parameterBag->bindParam($param, $variable, $type, $length);
    }

    /**
     * @inheritDoc
     */
    public function bindValue($param, $value, $type = ParameterType::STRING): bool
    {
        return $this->parameterBag->bindValue($param, $value, $type);
    }

    /**
     * Returns the sql that can be used in a query.
     *
     * There is one big modification needed:
     * Doctrine polyfills named parameters to numbered parameters.
     * The rds-data api _only_ supports named parameters.
     *
     * But numbered parameters aren't straight forward too.
     * Some implementations start the numbers with 1 and others with 0.
     */
    private function getSql(): string
    {
        return $this->parameterBag->prepareSqlStatement($this->sql);
    }

    public function errorCode(): mixed
    {
        // TODO: Implement errorCode() method.
        return false;
    }

    /**
     * @inheritDoc
     */
    public function errorInfo(): array
    {
        // TODO: Implement errorInfo() method.
        return [];
    }

    /**
     * @throws RdsDataException
     *
     * @inheritDoc
     */
    public function execute($params = null): bool
    {
        if (is_iterable($params)) {
            foreach ($params as $paramKey => $paramValue) {
                $this->bindValue($paramKey, $paramValue);
            }
        }

        $args = [
            'continueAfterTimeout' => preg_match(self::DDL_REGEX, $this->sql) > 0,
            'database' => $this->connection->getDatabase(),
            'includeResultMetadata' => true,
            'parameters' => $this->parameterBag->getParameters(),
            'resourceArn' => $this->connection->getResourceArn(), // REQUIRED
            'resultSetOptions' => [
                'decimalReturnType' => 'STRING',
            ],
            // 'schema' => '<string>',
            'secretArn' => $this->connection->getSecretArn(), // REQUIRED
            'sql' => $this->getSql(), // REQUIRED
        ];

        $transactionId = $this->connection->getTransactionId();
        if ($transactionId) {
            $args['transactionId'] = $transactionId;
        }

        $result = $this->connection->call('executeStatement', $args);

        if (! empty($result['generatedFields'])) {
            $generatedValue = $this->dataConverter->convertToValue(reset($result['generatedFields']));
            $this->connection->setLastInsertId((string) $generatedValue);
        }

        $this->result = new RdsDataResult($result, $this->dataConverter);
        $this->result->setFetchMode(...$this->fetchMode);

        return true;
    }

    public function rowCount(): int
    {
        return $this->result->rowCount();
    }

    public function getIterator(): Iterator
    {
        return $this->result->getIterator();
    }
}
