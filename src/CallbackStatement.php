<?php

declare(strict_types=1);

namespace Nemo64\DbalRdsData;

use Doctrine\DBAL\Cache\ArrayStatement;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;

class CallbackStatement extends ArrayStatement implements Statement
{
    /**
     * @var callable
     */
    private $callback;

    public function __construct(callable $callback)
    {
        parent::__construct([]);

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
    public function execute($params = null): bool
    {
        return ($this->callback)() ?? true;
    }

    public function rowCount(): int
    {
        return 0;
    }
}
