<?php

declare(strict_types=1);

namespace Nemo64\DbalRdsData;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\ParameterType;

use function addslashes;
use function base64_encode;
use function mb_detect_encoding;
use function sprintf;

/**
 * Keep some filler methods away from the main implementation to make it simpler
 */
abstract class AbstractConnection implements Connection
{
    /**
     * @inheritDoc
     */
    public function quote($value, $type = ParameterType::STRING): string
    {
        // If the input isn't ASCII then I'm not even gonna try escaping it because of possible multibyte attacks.
        // I just encode the input as base64 and let mysql decode it again.
        // I'm not 100% sure this works everywhere but it's better to have a failing query than a security hole.
        // If you actually need to escape user input: always prefer using parameters.
        if (mb_detect_encoding($value) !== 'ASCII') {
            return sprintf("FROM_BASE64('%s')", base64_encode($value));
        }

        return sprintf("'%s'", addslashes($value));
    }

    public function query(string $sql): Result
    {
        $stmt = $this->prepare($sql);

        return $stmt->execute();
    }

    /**
     * @see https://docs.aws.amazon.com/rdsdataservice/latest/APIReference/API_ExecuteStatement.html
     *
     * @inheritDoc
     */
    public function exec(string $sql): int|string
    {
        $stmt = $this->prepare($sql);
        $result = $stmt->execute();

        return $result->rowCount();
    }
}
