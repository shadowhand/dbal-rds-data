<?php

declare(strict_types=1);

namespace Nemo64\DbalRdsData;

use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;

use function preg_match;
use function reset;

/**
 * @see https://docs.aws.amazon.com/aws-sdk-php/v3/api/api-rds-data-2018-08-01.html
 * @see https://docs.aws.amazon.com/AmazonRDS/latest/AuroraUserGuide/data-api.html
 * @see https://docs.aws.amazon.com/rdsdataservice/latest/APIReference/API_ExecuteStatement.html
 */
class RdsDataStatement implements Statement
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

    public function __construct(private RdsDataConnection $connection, private string $sql, RdsDataConverter|null $dataConverter = null)
    {
        $this->dataConverter = $dataConverter ?? new RdsDataConverter();
        $this->parameterBag = new RdsDataParameterBag($this->dataConverter);
    }

    /**
     * @inheritDoc
     */
    public function bindValue(string|int $param, mixed $value, ParameterType $type): void
    {
        $this->parameterBag->bindValue($param, $value, $type);
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
    public function execute(): Result
    {
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

        return new RdsDataResult($result, $this->dataConverter);
    }
}
