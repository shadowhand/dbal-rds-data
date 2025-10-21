<?php

declare(strict_types=1);

namespace Nemo64\DbalRdsData;

use Aws\RDSDataService\Exception\RDSDataServiceException;
use Aws\RDSDataService\RDSDataServiceClient;
use Aws\Result;
use Doctrine\DBAL\Driver\Statement;

use function preg_match;
use function sleep;

class RdsDataConnection extends AbstractConnection
{
    private RdsDataConverter $dataConverter;

    private RdsDataStatement|null $lastStatement = null;

    private string|null $transactionId = null;

    private string|null $lastInsertedId = null;

    private int $pauseRetries = 0;

    private int $pauseRetryDelay = 5;

    public function __construct(private RDSDataServiceClient $client, private string $resourceArn, private string $secretArn, private string|null $database = null)
    {
        $this->dataConverter = new RdsDataConverter();
    }

    public function __destruct()
    {
        // Since this connection is actually connectionless,
        // I want to make sure that transactions aren't left to time out after a request.
        try {
            $this->rollBack();
        } catch (\Throwable $e) {
            // Silently ignore errors during cleanup
        }
    }

    /**
     * @inheritDoc
     */
    public function prepare($sql): Statement
    {
        // allow selecting a database by "use database;" statement
        if (preg_match('#^\s*use\s+(?:(\w+)|`([^`]+)`)\s*;?\s*$#i', $sql, $match)) {
            return new CallbackStatement(function () use ($match): void {
                $this->setDatabase($match[1] ?: $match[2]);
            });
        }

        $this->lastStatement = new RdsDataStatement($this, $sql, $this->dataConverter);

        return $this->lastStatement;
    }

    /**
     * @internal should only be used by the statement class
     */
    public function setLastInsertId(string $id): void
    {
        $this->lastInsertedId = $id;
    }

    /**
     * @inheritDoc
     */
    public function lastInsertId(): string|int
    {
        if ($this->lastInsertedId === null) {
            throw new \LogicException('No last insert ID available');
        }

        return $this->lastInsertedId;
    }

    /**
     * @see https://docs.aws.amazon.com/rdsdataservice/latest/APIReference/API_BeginTransaction.html
     *
     * @throws RdsDataException
     *
     * @inheritDoc
     */
    public function beginTransaction(): void
    {
        if ($this->transactionId !== null) {
            throw new \LogicException('Transaction already started');
        }

        $args = [
            'database' => $this->database,
            'resourceArn' => $this->resourceArn,
            'secretArn' => $this->secretArn,
        ];

        $response = $this->call('beginTransaction', $args);
        $this->transactionId = $response['transactionId'];
    }

    /**
     * @see https://docs.aws.amazon.com/rdsdataservice/latest/APIReference/API_CommitTransaction.html
     *
     * @throws RdsDataException
     *
     * @inheritDoc
     */
    public function commit(): void
    {
        if ($this->transactionId === null) {
            return;
        }

        $args = [
            'resourceArn' => $this->resourceArn,
            'secretArn' => $this->secretArn,
            'transactionId' => $this->transactionId,
        ];

        $this->call('commitTransaction', $args);
        $this->transactionId = null;
    }

    /**
     * @see https://docs.aws.amazon.com/rdsdataservice/latest/APIReference/API_RollbackTransaction.html
     *
     * @throws RdsDataException
     *
     * @inheritDoc
     */
    public function rollBack(): void
    {
        if ($this->transactionId === null) {
            return;
        }

        $args = [
            'resourceArn' => $this->resourceArn,
            'secretArn' => $this->secretArn,
            'transactionId' => $this->transactionId,
        ];

        $this->call('rollbackTransaction', $args);
        $this->transactionId = null;
    }

    public function errorCode(): string|null
    {
        if ($this->lastStatement === null) {
            return null;
        }

        return $this->lastStatement->errorCode();
    }

    /**
     * @inheritDoc
     */
    public function errorInfo(): array
    {
        if ($this->lastStatement === null) {
            return [];
        }

        return $this->lastStatement->errorInfo();
    }

    public function getClient(): RDSDataServiceClient
    {
        return $this->client;
    }

    public function getNativeConnection(): RDSDataServiceClient
    {
        return $this->client;
    }

    public function getServerVersion(): string
    {
        // RDS Data API doesn't provide direct server version access
        // Return a reasonable default for MySQL 8.0 (common Aurora version)
        return '8.0.0';
    }

    public function getResourceArn(): string
    {
        return $this->resourceArn;
    }

    public function getSecretArn(): string
    {
        return $this->secretArn;
    }

    public function getDatabase(): string|null
    {
        return $this->database;
    }

    public function setDatabase(string|null $database): void
    {
        $this->database = $database;
    }

    public function getTransactionId(): string|null
    {
        return $this->transactionId;
    }

    public function getPauseRetries(): int
    {
        return $this->pauseRetries;
    }

    public function setPauseRetries(int $pauseRetries): void
    {
        $this->pauseRetries = $pauseRetries;
    }

    public function getPauseRetryDelay(): int
    {
        return $this->pauseRetryDelay;
    }

    public function setPauseRetryDelay(int $pauseRetryDelay): void
    {
        $this->pauseRetryDelay = $pauseRetryDelay;
    }

    /**
     * Runs a rds data command and handles errors.
     *
     * @throws RdsDataException
     */
    public function call(string $command, array $args, int $retry = 0): Result
    {
        try {
            return $this->client->__call($command, [$args]);
        } catch (RDSDataServiceException $exception) {
            if ($exception->getAwsErrorCode() !== 'BadRequestException') {
                throw $exception;
            }

            $interpretedException = RdsDataException::interpretErrorMessage($exception->getAwsErrorMessage());
            if ($interpretedException->getErrorCode() === '6000' && $this->getPauseRetries() > $retry) {
                sleep($this->getPauseRetryDelay());

                return $this->call($command, $args, $retry + 1);
            }

            throw $interpretedException;
        }
    }
}
