<?php

declare(strict_types=1);

namespace Nemo64\DbalRdsData;

use Doctrine\DBAL\Driver\Result;

/**
 * Empty result implementation for callback statements that don't return data.
 */
class CallbackResult implements Result
{
    public function fetchNumeric(): array|false
    {
        return false;
    }

    public function fetchAssociative(): array|false
    {
        return false;
    }

    public function fetchOne(): mixed
    {
        return false;
    }

    public function fetchAllNumeric(): array
    {
        return [];
    }

    public function fetchAllAssociative(): array
    {
        return [];
    }

    public function fetchFirstColumn(): array
    {
        return [];
    }

    public function rowCount(): int
    {
        return 0;
    }

    public function columnCount(): int
    {
        return 0;
    }

    public function free(): void
    {
        // Nothing to free
    }
}
