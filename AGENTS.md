# CLAUDE.md

## Project Overview

**Name**: dbal-rds-data
**Description**: Doctrine DBAL driver for AWS RDS Data API
**License**: MIT
**PHP Version**: 8.3+

This library provides a Doctrine DBAL driver implementation that enables database access through the AWS RDS Data API instead of traditional MySQL connections. It's designed for Aurora Serverless databases and allows applications to interact with databases without managing persistent connections or VPC configurations.

## Purpose

This driver bridges the gap between Doctrine DBAL (a database abstraction layer used by PHP applications) and AWS RDS Data API (a REST API for executing SQL statements on Aurora Serverless). Key benefits include:

- **Serverless-first architecture**: No persistent database connections needed
- **Simplified AWS security**: Uses IAM roles instead of managing database passwords
- **VPC-free deployment**: Eliminates need for VPC configuration and NAT gateways
- **Connection pooling**: AWS manages connection pooling automatically

## Architecture

### Core Components

#### 1. **RdsDataDriver** (`src/RdsDataDriver.php`)
- Entry point implementing Doctrine's `Driver` interface
- Extends `AbstractMySQLDriver` for MySQL-specific functionality
- Handles connection creation and exception mapping
- Converts AWS-specific errors (like error code 6000 for paused databases) to DBAL exceptions

#### 2. **RdsDataConnection** (`src/RdsDataConnection.php`)
- Implements DBAL's `Connection` interface
- Manages AWS RDSDataServiceClient
- Handles transaction lifecycle (begin, commit, rollback)
- Provides database selection via "USE" statements
- Manages pause/retry logic for serverless databases

#### 3. **RdsDataStatement** (`src/RdsDataStatement.php`)
- Implements DBAL's `Statement` interface
- Converts SQL statements to RDS Data API calls
- Manages parameter binding and SQL preparation
- Handles DDL detection for `continueAfterTimeout` flag
- Delegates result handling to `RdsDataResult`

#### 4. **RdsDataResult** (`src/RdsDataResult.php`)
- Implements DBAL's `Result` interface
- Converts AWS RDS Data API results to standard PHP data structures
- Supports multiple fetch methods (fetchAssociative, fetchNumeric, fetchOne, etc.)
- Handles column metadata and row iteration
- Provides `getColumnName()` for column name retrieval by index

#### 5. **RdsDataParameterBag** (`src/RdsDataParameterBag.php`)
- Manages SQL parameter binding
- Converts positional parameters (`?`) to named parameters (`:0`, `:1`, etc.)
- Handles parameter type conversion via `RdsDataConverter`

#### 6. **RdsDataConverter** (`src/RdsDataConverter.php`)
- Bidirectional conversion between PHP and RDS Data API formats
- PHP → RDS: Converts PHP values to AWS Field format (blobValue, booleanValue, longValue, stringValue, isNull)
- RDS → PHP: Converts AWS Field format back to native PHP types

#### 7. **RdsDataException** (`src/RdsDataException.php`)
- Custom exception class for RDS-specific errors
- Uses regex to extract MySQL error codes from error messages
- Maps common MySQL errors (1044-1701) plus custom error 6000 (paused database)
- Generated from MySQL error documentation

#### 8. **AbstractConnection** (`src/AbstractConnection.php`)
- Base class providing common connection methods
- Implements `quote()` for string escaping with multibyte attack prevention
- Provides `query()` and `exec()` helper methods

#### 9. **CallbackStatement** (`src/CallbackStatement.php`)
- Special statement implementation for custom operations
- Used internally for "USE database" statements
- Executes callbacks instead of actual SQL queries

### Data Flow

```
Application Code
    ↓
Doctrine DBAL API
    ↓
RdsDataDriver
    ↓
RdsDataConnection
    ↓
RdsDataStatement
    ↓
RdsDataParameterBag → RdsDataConverter
    ↓
AWS RDS Data API (executeStatement)
    ↓
RdsDataResult ← RdsDataConverter
    ↓
Application Code
```

## Key Technical Decisions

### 1. Parameter Conversion
**Challenge**: AWS RDS Data API only supports named parameters (`:name`), but Doctrine primarily uses positional parameters (`?`).

**Solution**: `RdsDataParameterBag` converts `?` placeholders to `:0`, `:1`, etc., while avoiding replacement within string literals using regex.

### 2. Error Code Extraction
**Challenge**: AWS RDS Data API only returns error messages, not MySQL error codes.

**Solution**: `RdsDataException` uses a comprehensive regex (generated from MySQL documentation) to extract error codes from messages.

### 3. String Escaping
**Challenge**: No connection-aware escape function available in the REST API.

**Solution**:
- ASCII strings: Pass through `addslashes()`
- Non-ASCII strings: Base64 encode and use `FROM_BASE64()` to prevent multibyte injection attacks

### 4. Paused Database Handling
**Challenge**: Aurora Serverless databases can pause, causing connection failures.

**Solution**:
- Map paused database errors to custom error code 6000
- Convert to `Doctrine\DBAL\Exception\ConnectionException`
- Provide retry mechanism with configurable delays (`pauseRetries`, `pauseRetryDelay`)

### 5. Transaction Support
**Challenge**: Stateless API needs to maintain transaction state.

**Solution**:
- Store `transactionId` from `beginTransaction` response
- Include `transactionId` in all subsequent queries
- Clear `transactionId` on commit/rollback
- Automatically rollback in destructor to prevent abandoned transactions

### 6. DDL Query Detection
**Challenge**: DDL queries (CREATE, DROP, ALTER, TRUNCATE) can't run in transactions and may take longer.

**Solution**:
- Regex pattern detects DDL statements
- Automatically set `continueAfterTimeout: true` for DDL queries
- Allows schema operations to complete beyond 45-second timeout

## Configuration

### Connection Parameters

```php
[
    'driverClass' => \Nemo64\DbalRdsData\RdsDataDriver::class,
    'host' => 'eu-west-1', // AWS region
    'user' => '[aws-api-key]', // optional if in environment
    'password' => '[aws-api-secret]', // optional if in environment
    'dbname' => 'mydb',
    'driverOptions' => [
        'resourceArn' => 'arn:aws:rds:...:cluster:...',
        'secretArn' => 'arn:aws:secretsmanager:...:secret:...',
        'timeout' => 45, // Default: 45 seconds
        'pauseRetries' => 0, // Default: 0 (no retries)
        'pauseRetryDelay' => 10, // Default: 10 seconds
    ]
]
```

## Testing Strategy

The test suite uses mocked AWS clients to avoid requiring actual AWS infrastructure:

- **RdsDataServiceClientTrait**: Provides mock client setup
- **Test isolation**: Each test uses fresh connection instances
- **Mock responses**: Tests define expected API calls and responses
- **Coverage**: Tests cover transactions, parameter binding, fetch modes, error handling, and edge cases

### Running Tests

```bash
composer test
# or
vendor/bin/phpunit
```

## Project Structure

```
src/
├── AbstractConnection.php      # Base connection functionality
├── CallbackStatement.php       # Special statement for internal operations
├── RdsDataConnection.php       # Main connection implementation
├── RdsDataConverter.php        # Data format conversion
├── RdsDataDriver.php          # Driver entry point
├── RdsDataException.php        # Custom exception with error code extraction
├── RdsDataParameterBag.php    # Parameter binding and conversion
├── RdsDataResult.php          # Result set handling
└── RdsDataStatement.php        # SQL statement execution

tests/
├── RdsDataConnectionTest.php   # Connection and transaction tests
├── RdsDataConverterTest.php    # Data conversion tests
├── RdsDataExceptionTest.php    # Exception handling tests
├── RdsDataParameterBagTest.php # Parameter binding tests
├── RdsDataResultTest.php       # Result fetching tests
├── RdsDataServiceClientTrait.php # Test utilities
├── RdsDataStatementTest.php    # Statement execution tests
└── TestClasses/
    └── ClassWithConstructor.php # Test fixture
```

## Dependencies

### Production
- **PHP**: ^8.3
- **aws/aws-sdk-php**: ^3.98 (RDS Data Service client)
- **doctrine/dbal**: ^4.0 (Database abstraction layer)

### Development
- **phpunit/phpunit**: ^12.0 (Testing framework)
- **doctrine/coding-standard**: ^14.0 (Code style)

## Known Limitations

1. **Request Rate Limit**: 1000 requests/second per AWS account (not per database)
2. **Regional Availability**: RDS Data API not available in all AWS regions
3. **Database Support**: MySQL/Aurora only (no PostgreSQL support yet)
4. **No Session Variables**: Cannot set transaction isolation levels or session variables
5. **Size Restrictions**: ExecuteStatement has payload size limits (though not strictly enforced)
6. **Timeout**: Maximum 45-second query execution (DDL can continue via `continueAfterTimeout`)
7. **Cold Start**: Paused databases take 30s-2min to resume

## Contributing Guidelines

1. **Code Style**: Follow Doctrine coding standards
2. **Testing**: All new features must include tests
3. **Type Safety**: Use strict types and proper return type declarations
4. **Documentation**: Update README.md and this file for significant changes
5. **Backward Compatibility**: Maintain compatibility with Doctrine DBAL 4.0+

## Useful Commands

```bash
# Run tests
composer test

# Run tests with coverage
vendor/bin/phpunit --coverage-html coverage/

# Check coding standards
vendor/bin/phpcs

# Fix coding standards
vendor/bin/phpcbf
```

## AWS Resources

### Required IAM Permissions

```json
{
    "Effect": "Allow",
    "Action": [
        "rds-data:ExecuteStatement",
        "rds-data:BeginTransaction",
        "rds-data:CommitTransaction",
        "rds-data:RollbackTransaction",
        "secretsmanager:GetSecretValue"
    ],
    "Resource": "*"
}
```

### Service Quotas to Monitor

- **Data API requests per second**: 1000 (account-wide)
- **Concurrent connections**: Managed by AWS
- **Query timeout**: 45 seconds (can continue for DDL)

## Debugging Tips

### Enable AWS SDK Logging

```php
$client = new RDSDataServiceClient([
    'version' => '2018-08-01',
    'region' => 'eu-west-1',
    'debug' => true, // Enable debug logging
]);
```

### Access Raw Client

```php
$dbalConnection = DriverManager::getConnection($params);
$awsClient = $dbalConnection->getNativeConnection()->getClient();
// Use $awsClient for direct AWS API calls
```

### Handle Paused Database

```php
try {
    $conn->executeQuery('SELECT * FROM users');
} catch (\Doctrine\DBAL\Exception\ConnectionException $e) {
    if ($e->getPrevious() && $e->getPrevious()->getErrorCode() === '6000') {
        // Database is paused, show user-friendly message
        echo "Database is starting up, please try again in a moment";
    }
}
```

## Additional Resources

- [AWS RDS Data API Documentation](https://docs.aws.amazon.com/AmazonRDS/latest/AuroraUserGuide/data-api.html)
- [Doctrine DBAL Documentation](https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/)
- [Author's Blog Post on Shared Aurora Serverless](https://www.marco.zone/shared-aurora-serverless-using-cloudformation)
- [MySQL Error Reference](https://dev.mysql.com/doc/refman/5.6/en/server-error-reference.html)

## Authors

- **Marco Pfeiffer** - Original author (git@marco.zone)
- **Woody Gilk** - Maintainer (woody.gilk@gmail.com)

## License

This project is licensed under the MIT License. See LICENSE file for details.
