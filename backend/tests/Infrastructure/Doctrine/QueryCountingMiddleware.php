<?php

namespace App\Tests\Infrastructure\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\When;

#[When('test')]
#[Autoconfigure(public: true)]
#[AsMiddleware]
final class QueryCountingMiddleware implements Middleware
{
    private int $queryCount = 0;
    private int $selectCount = 0;

    public function wrap(Driver $driver): Driver
    {
        return new class($driver, $this) extends AbstractDriverMiddleware {
            public function __construct(
                Driver $driver,
                private readonly QueryCountingMiddleware $counter,
            ) {
                parent::__construct($driver);
            }

            public function connect(#[\SensitiveParameter] array $params): Connection
            {
                return new class(parent::connect($params), $this->counter) extends AbstractConnectionMiddleware {
                    public function __construct(
                        Connection $connection,
                        private readonly QueryCountingMiddleware $counter,
                    ) {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        $statement = parent::prepare($sql);

                        return new class($statement, $this->counter, $sql) extends AbstractStatementMiddleware {
                            public function __construct(
                                Statement $statement,
                                private readonly QueryCountingMiddleware $counter,
                                private readonly string $sql,
                            ) {
                                parent::__construct($statement);
                            }

                            public function execute(): Result
                            {
                                $this->counter->increment($this->sql);

                                return parent::execute();
                            }
                        };
                    }

                    public function query(string $sql): Result
                    {
                        $this->counter->increment($sql);

                        return parent::query($sql);
                    }

                    public function exec(string $sql): int|string
                    {
                        $this->counter->increment($sql);

                        return parent::exec($sql);
                    }
                };
            }
        };
    }

    public function reset(): void
    {
        $this->queryCount = 0;
        $this->selectCount = 0;
    }

    public function increment(string $sql = ''): void
    {
        ++$this->queryCount;
        if (str_starts_with(ltrim(strtoupper($sql)), 'SELECT')) {
            ++$this->selectCount;
        }
    }

    public function count(): int
    {
        return $this->queryCount;
    }

    public function selectCount(): int
    {
        return $this->selectCount;
    }
}
