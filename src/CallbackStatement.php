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
    public function bindValue($param, $value, $type = ParameterType::STRING): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function bindParam($param, &$variable, $type = ParameterType::STRING, $length = null): bool
    {
        return false;
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
    public function execute($params = null): Result
    {
        ($this->callback)();

        return new CallbackResult();
    }
}
