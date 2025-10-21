<?php

declare(strict_types=1);

namespace Nemo64\DbalRdsData;

use Doctrine\DBAL\ParameterType;
use LogicException;
use RuntimeException;

use function array_filter;
use function array_flip;
use function array_keys;
use function count;
use function get_defined_constants;
use function is_string;
use function min;
use function preg_last_error;
use function preg_replace_callback;
use function str_ends_with;

use const ARRAY_FILTER_USE_KEY;

class RdsDataParameterBag
{
    /**
     * This expression can be used to find numeric parameters in an sql statement.
     * It understands strings and prevents matching within them.
     */
    private const string NUMERIC_PARAMETER_EXPRESSION = '/\?(?=([^\'"`]+|\'([^\']|\\\\\')*\'|"([^"]|\\\\")*"|`([^`]|\\\\`)*`)*$)/';

    /**
     * @see https://docs.aws.amazon.com/aws-sdk-php/v3/api/api-rds-data-2018-08-01.html#shape-sqlparameter
     * @see https://docs.aws.amazon.com/aws-sdk-php/v3/api/api-rds-data-2018-08-01.html#executestatement
     * @see https://docs.aws.amazon.com/rdsdataservice/latest/APIReference/API_SqlParameter.html
     * @see https://docs.aws.amazon.com/rdsdataservice/latest/APIReference/API_Field.html
     */
    private array $parameters = [];

    private RdsDataConverter $dataConverter;

    public function __construct(RdsDataConverter|null $dataConverter = null)
    {
        $this->dataConverter = $dataConverter ?? new RdsDataConverter();
    }

    /**
     * @inheritDoc
     */
    public function bindValue($param, $value, $type = ParameterType::STRING): bool
    {
        // The difference between bindValue and bindParam is that bindParam takes a reference.
        // https://stackoverflow.com/questions/1179874/what-is-the-difference-between-bindparam-and-bindvalue
        // I decided not to support that for simplicity.
        // It might create issues with some implementations that rely on that fact.
        return $this->bindParam($param, $value, $type);
    }

    /**
     * @inheritDoc
     */
    public function bindParam($column, &$variable, $type = ParameterType::STRING, $length = null): bool
    {
        if ($length !== null) {
            throw new RuntimeException('length parameter not implemented.');
        }

        $this->parameters[$column] = [&$variable, $type];

        return true;
    }

    /**
     * Because the variable is only "bound" and can therefore change,
     * I must convert the representation just before sending the query.
     */
    public function getParameters(): array
    {
        $result = [];

        foreach ($this->parameters as $column => $arguments) {
            $result[] = [
                'name' => (string) $column,
                'value' => $this->dataConverter->convertToJson(...$arguments),
            ];
        }

        return $result;
    }

    /**
     * The rds data api only supports named parameters but most dbal implementations heavily use numeric parameters.
     *
     * This method converts "?" into ":0" parameters.
     */
    public function prepareSqlStatement(string $sql): string
    {
        $numericParameters = array_filter(array_keys($this->parameters), 'is_int');
        if (count($numericParameters) <= 0) {
            return $sql;
        }

        // it is valid to start numeric parameters 0 and 1
        $index = min($numericParameters);
        if ($index !== 0 && $index !== 1) {
            throw new LogicException('Numeric parameters must start with 0 or 1.');
        }

        $createParameter = static function () use (&$index) {
            return ':' . $index++;
        };

        $sql = preg_replace_callback(self::NUMERIC_PARAMETER_EXPRESSION, $createParameter, $sql);
        if (! is_string($sql)) {
            // snipped from https://www.php.net/manual/de/function.preg-last-error.php#124124
            $pregError = array_flip(array_filter(get_defined_constants(true)['pcre'], static function (int $value): bool {
                return str_ends_with((string) $value, '_ERROR');
            }, ARRAY_FILTER_USE_KEY))[preg_last_error()] ?? 'unknown error';

            throw new RuntimeException("sql param replacement failed: $pregError");
        }

        return $sql;
    }
}
