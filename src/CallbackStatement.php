<?php

declare(strict_types=1);

namespace Nemo64\DbalRdsData;

use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;

class CallbackStatement implements Statement
{
    /**
     * @var callable
     */
    private $callback;

    public function __construct(callable $callback)
    {
        $this->callback = $callback;
    }

    /**
     * @inheritDoc
     */
    public function bindValue(string|int $param, mixed $value, ParameterType $type): void
    {
        // No-op for callback statements
    }

    public function errorCode(): mixed
    {
        return false;
    }

    public function errorInfo(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function execute(): Result
    {
        ($this->callback)();

        return new CallbackResult();
    }
}
