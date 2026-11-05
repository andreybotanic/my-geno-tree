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
                        return new class(parent::prepare($sql), $this->counter) extends AbstractStatementMiddleware {
                            public function __construct(
                                Statement $statement,
                                private readonly QueryCountingMiddleware $counter,
                            ) {
                                parent::__construct($statement);
                            }

                            public function execute(): Result
                            {
                                $this->counter->increment();

                                return parent::execute();
                            }
                        };
                    }

                    public function query(string $sql): Result
                    {
                        $this->counter->increment();

                        return parent::query($sql);
                    }

                    public function exec(string $sql): int|string
                    {
                        $this->counter->increment();

                        return parent::exec($sql);
                    }
                };
            }
        };
    }

    public function reset(): void
    {
        $this->queryCount = 0;
    }

    public function increment(): void
    {
        ++$this->queryCount;
    }

    public function count(): int
    {
        return $this->queryCount;
    }
}
