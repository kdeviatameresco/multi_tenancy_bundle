<?php

namespace Hakam\MultiTenancyBundle\Test;

use Hakam\MultiTenancyBundle\Config\TenantConnectionConfigDTO;
use Hakam\MultiTenancyBundle\Enum\DatabaseStatusEnum;
use Hakam\MultiTenancyBundle\Enum\DriverTypeEnum;
use Hakam\MultiTenancyBundle\Exception\TenantNotFoundException;
use Hakam\MultiTenancyBundle\Port\TenantConfigProviderInterface;

/**
 * In-memory TenantConfigProviderInterface implementation for tests.
 *
 * Lets unit tests bypass the default Doctrine-backed provider so the bundle's
 * "switch to tenant 123" path doesn't need a real metadata database. Wire it
 * up via `tenant_config_provider: 'app.test.in_memory_provider'` (or any
 * service ID) and seed identifiers via the fluent builders.
 *
 * Shipped under src/Test/ rather than tests/ so consumer projects can `use`
 * it without depending on the bundle's dev autoload.
 */
final class InMemoryTenantConfigProvider implements TenantConfigProviderInterface
{
    /** @var array<array-key, TenantConnectionConfigDTO> */
    private array $tenants = [];

    /**
     * @param array<array-key, TenantConnectionConfigDTO> $tenants
     */
    public function __construct(array $tenants = [])
    {
        foreach ($tenants as $identifier => $config) {
            $this->tenants[$identifier] = $config;
        }
    }

    public function addTenant(mixed $identifier, TenantConnectionConfigDTO $config): self
    {
        $this->tenants[$identifier] = $config;

        return $this;
    }

    public function withMysqlTenant(
        mixed $identifier,
        string $dbname,
        string $host = '127.0.0.1',
        int $port = 3306,
        string $user = 'root',
        ?string $password = null,
        DatabaseStatusEnum $status = DatabaseStatusEnum::DATABASE_CREATED,
    ): self {
        return $this->addTenant($identifier, TenantConnectionConfigDTO::fromArgs(
            identifier: $identifier,
            driver: DriverTypeEnum::MYSQL,
            dbStatus: $status,
            host: $host,
            port: $port,
            dbname: $dbname,
            user: $user,
            password: $password,
        ));
    }

    public function withPostgresTenant(
        mixed $identifier,
        string $dbname,
        string $host = '127.0.0.1',
        int $port = 5432,
        string $user = 'postgres',
        ?string $password = null,
        DatabaseStatusEnum $status = DatabaseStatusEnum::DATABASE_CREATED,
    ): self {
        return $this->addTenant($identifier, TenantConnectionConfigDTO::fromArgs(
            identifier: $identifier,
            driver: DriverTypeEnum::POSTGRES,
            dbStatus: $status,
            host: $host,
            port: $port,
            dbname: $dbname,
            user: $user,
            password: $password,
        ));
    }

    public function withSqliteTenant(
        mixed $identifier,
        string $path = ':memory:',
        DatabaseStatusEnum $status = DatabaseStatusEnum::DATABASE_CREATED,
    ): self {
        return $this->addTenant($identifier, TenantConnectionConfigDTO::fromArgs(
            identifier: $identifier,
            driver: DriverTypeEnum::SQLITE,
            dbStatus: $status,
            host: 'localhost',
            port: 0,
            dbname: $path,
            user: 'test',
            password: null,
        ));
    }

    public function getTenantConnectionConfig(mixed $identifier): TenantConnectionConfigDTO
    {
        if (!array_key_exists($identifier, $this->tenants)) {
            throw TenantNotFoundException::forIdentifier($identifier, array_keys($this->tenants));
        }

        return $this->tenants[$identifier];
    }

    /**
     * @return array<array-key, TenantConnectionConfigDTO>
     */
    public function all(): array
    {
        return $this->tenants;
    }

    public function clear(): void
    {
        $this->tenants = [];
    }
}
