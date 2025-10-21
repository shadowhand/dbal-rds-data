<?php

declare(strict_types=1);

namespace Nemo64\DbalRdsData;

use Doctrine\DBAL\ParameterType;
use RuntimeException;

use function array_map;
use function base64_decode;
use function base64_encode;
use function current;
use function is_resource;
use function key;
use function rewind;
use function stream_get_contents;

/**
 * This class provides methods to convert php data to the rds-data representation.
 *
 * @see https://docs.aws.amazon.com/rdsdataservice/latest/APIReference/API_Field.html
 */
class RdsDataConverter
{
    public function convertToJson(mixed $value, ParameterType $type): array
    {
        switch ($value === null ? ParameterType::NULL : $type) {
            case ParameterType::LARGE_OBJECT:
                if (is_resource($value)) {
                    rewind($value);
                    $value = stream_get_contents($value);
                }

                $value = base64_encode($value);

                return ['blobValue' => $value];

            case ParameterType::BOOLEAN:
                return ['booleanValue' => (bool) $value];

            // missing double because there is no official double type

            case ParameterType::NULL:
                return ['isNull' => true];

            case ParameterType::INTEGER:
                return ['longValue' => (int) $value];

            case ParameterType::STRING:
                return ['stringValue' => (string) $value];
        }

        throw new RuntimeException("Type $type is not implemented.");
    }

    /**
     * Results from rds are formatted in an array like this:
     * ['stringValue' => 'string']
     * ['longValue' => 5]
     * ['isNull' => true]
     *
     * This method converts this to a normal array that you'd expect.
     */
    public function convertToValue(array $json): mixed
    {
        $key = key($json);
        $value = current($json);

        switch ($key) {
            case 'isNull':
                return null;

            case 'blobValue':
                return base64_decode($value);

            case 'arrayValue':
                throw new RuntimeException('arrayValue is not implemented.');

            case 'structValue':
                return array_map([$this, 'convertToValue'], $value);

            // case 'booleanValue':
            // case 'longValue':
            // case 'stringValue':
            default:
                return $value;
        }
    }
}
