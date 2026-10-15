<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\Migration\Version20261015000000;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DelegatedUserAdministrationMigrationTest extends TestCase
{
    public function testUpgradePreservesAccountsAndDelegatesNothingByDefault(): void
    {
        $host = getenv('DELEGATION_TEST_MYSQL_HOST');
        if (!$host) {
            self::markTestSkipped('Set DELEGATION_TEST_MYSQL_HOST to an isolated MariaDB test container.');
        }
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_mysql', 'host' => $host, 'user' => 'root',
            'password' => (string)getenv('DELEGATION_TEST_MYSQL_PASSWORD'), 'dbname' => 'delegation_test',
        ]);
        $connection->executeStatement('CREATE TABLE role (id INT PRIMARY KEY, name VARCHAR(100))');
        $connection->executeStatement('CREATE TABLE users (id INT PRIMARY KEY, email VARCHAR(100))');
        $connection->executeStatement("INSERT INTO role VALUES (1, 'Owner'), (2, 'DJ')");
        $connection->executeStatement("INSERT INTO users VALUES (1, 'owner@example.com')");
        try {
            $migration = new Version20261015000000($connection, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $connection->executeStatement($query->getStatement());
            }
            self::assertSame('owner@example.com', $connection->fetchOne('SELECT email FROM users WHERE id = 1'));
            self::assertSame(0, (int)$connection->fetchOne('SELECT is_protected FROM users WHERE id = 1'));
            self::assertSame(0, (int)$connection->fetchOne('SELECT is_protected FROM role WHERE id = 2'));
            self::assertSame(0, (int)$connection->fetchOne('SELECT COUNT(*) FROM role_delegation'));
            $connection->executeStatement('INSERT INTO role_delegation VALUES (1, 2)');
            $connection->executeStatement('DELETE FROM role WHERE id = 2');
            self::assertSame([], $connection->fetchAllAssociative('SELECT * FROM role_delegation'));

            $rollback = new Version20261015000000($connection, new NullLogger());
            $rollback->down(new Schema());
            foreach ($rollback->getSql() as $query) {
                $connection->executeStatement($query->getStatement());
            }
            self::assertSame('owner@example.com', $connection->fetchOne('SELECT email FROM users WHERE id = 1'));
        } finally {
            $connection->executeStatement('DROP TABLE IF EXISTS role_delegation');
            $connection->executeStatement('DROP TABLE users');
            $connection->executeStatement('DROP TABLE role');
            $connection->close();
        }
    }
}
