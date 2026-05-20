<?php

namespace Hakam\MultiTenancyBundle\Tests\Unit\Test;

use Hakam\MultiTenancyBundle\Config\TenantConnectionConfigDTO;
use Hakam\MultiTenancyBundle\Enum\DatabaseStatusEnum;
use Hakam\MultiTenancyBundle\Enum\DriverTypeEnum;
use Hakam\MultiTenancyBundle\Exception\TenantNotFoundException;
use Hakam\MultiTenancyBundle\Test\InMemoryTenantConfigProvider;
use PHPUnit\Framework\TestCase;

class InMemoryTenantConfigProviderTest extends TestCase
{
    public function testFluentMysqlTenantBuilder(): void
    {
        $provider = (new InMemoryTenantConfigProvider())
            ->withMysqlTenant(123, 'test_db123', host: 'tenant-rds.acme.local', port: 3306, user: 'app', password: 'secret');

        $config = $provider->getTenantConnectionConfig(123);

        $this->assertSame(123, $config->identifier);
        $this->assertSame(DriverTypeEnum::MYSQL, $config->driver);
        $this->assertSame('tenant-rds.acme.local', $config->host);
        $this->assertSame('test_db123', $config->dbname);
        $this->assertSame('app', $config->user);
        $this->assertSame('secret', $config->password);
    }

    public function testFluentPostgresTenantBuilderUsesPostgresDefaults(): void
    {
        $provider = (new InMemoryTenantConfigProvider())
            ->withPostgresTenant(1, 'tenant_db');

        $config = $provider->getTenantConnectionConfig(1);

        $this->assertSame(DriverTypeEnum::POSTGRES, $config->driver);
        $this->assertSame(5432, $config->port);
        $this->assertSame('postgres', $config->user);
    }

    public function testFluentSqliteTenantBuilderDefaultsToInMemory(): void
    {
        $provider = (new InMemoryTenantConfigProvider())
            ->withSqliteTenant('tenant_alpha');

        $config = $provider->getTenantConnectionConfig('tenant_alpha');

        $this->assertSame(DriverTypeEnum::SQLITE, $config->driver);
        $this->assertSame(':memory:', $config->dbname);
    }

    public function testAddTenantAcceptsRawDto(): void
    {
        $dto = TenantConnectionConfigDTO::fromArgs(
            identifier: 99,
            driver: DriverTypeEnum::MYSQL,
            dbStatus: DatabaseStatusEnum::DATABASE_MIGRATED,
            host: 'h',
            port: 1,
            dbname: 'd',
            user: 'u',
        );

        $provider = (new InMemoryTenantConfigProvider())->addTenant(99, $dto);

        $this->assertSame($dto, $provider->getTenantConnectionConfig(99));
    }

    public function testGetTenantConnectionConfigThrowsWithRichContext(): void
    {
        $provider = (new InMemoryTenantConfigProvider())
            ->withMysqlTenant(1, 'one')
            ->withMysqlTenant(2, 'two');

        try {
            $provider->getTenantConnectionConfig(99);
            $this->fail('Expected TenantNotFoundException');
        } catch (TenantNotFoundException $e) {
            $this->assertStringContainsString('99', $e->getMessage());
            $this->assertStringContainsString('known identifiers', $e->getMessage());
            $this->assertStringContainsString('1', $e->getMessage());
            $this->assertStringContainsString('2', $e->getMessage());
        }
    }

    public function testGetTenantConnectionConfigThrowsWhenEmpty(): void
    {
        $provider = new InMemoryTenantConfigProvider();

        $this->expectException(TenantNotFoundException::class);
        $this->expectExceptionMessageMatches('/no tenants are registered/');

        $provider->getTenantConnectionConfig('anything');
    }

    public function testConstructorAcceptsPreseededTenants(): void
    {
        $dto = TenantConnectionConfigDTO::fromArgs(
            identifier: 5,
            driver: DriverTypeEnum::MYSQL,
            dbStatus: DatabaseStatusEnum::DATABASE_CREATED,
            host: 'h',
            port: 1,
            dbname: 'd',
            user: 'u',
        );

        $provider = new InMemoryTenantConfigProvider([5 => $dto]);

        $this->assertSame($dto, $provider->getTenantConnectionConfig(5));
    }

    public function testClearRemovesAllTenants(): void
    {
        $provider = (new InMemoryTenantConfigProvider())
            ->withMysqlTenant(1, 'one')
            ->withMysqlTenant(2, 'two');

        $this->assertCount(2, $provider->all());

        $provider->clear();

        $this->assertSame([], $provider->all());
    }
}
