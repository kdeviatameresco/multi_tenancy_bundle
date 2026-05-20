<?php

declare(strict_types=1);

namespace Hakam\MultiTenancyBundle\Context;

use Hakam\MultiTenancyBundle\Config\TenantConnectionConfigDTO;
use Hakam\MultiTenancyBundle\Enum\DriverTypeEnum;
use Hakam\MultiTenancyBundle\Event\TenantSwitchedEvent;
use Symfony\Contracts\Service\ResetInterface;

class TenantContext implements TenantContextInterface, ResetInterface
{
    private ?string $tenantId = null;
    private mixed $activeTenantIdentifier = null;
    private ?TenantConnectionConfigDTO $activeTenantConfig = null;

    public function getTenantId(): ?string
    {
        return $this->tenantId;
    }

    /**
     * Returns the active tenant identifier in its original form (not stringified).
     * Use this when the identifier type matters — e.g. consumer code that switched
     * on an int tenant ID and wants to compare against the same int later.
     */
    public function getActiveTenantIdentifier(): mixed
    {
        return $this->activeTenantIdentifier;
    }

    /**
     * Returns the full TenantConnectionConfigDTO that drove the active switch,
     * or null if no tenant is currently active. Useful for diagnostics, audit
     * logs, and tests that need to assert on host/port/driver/dbname.
     */
    public function getActiveTenantConfig(): ?TenantConnectionConfigDTO
    {
        return $this->activeTenantConfig;
    }

    /**
     * Returns the active tenant DSN reconstructed from the active TenantConnectionConfigDTO,
     * or null if no tenant is currently active. Password is masked.
     */
    public function getActiveDsn(): ?string
    {
        if ($this->activeTenantConfig === null) {
            return null;
        }

        $config = $this->activeTenantConfig;
        $scheme = match ($config->driver) {
            DriverTypeEnum::POSTGRES => 'pgsql',
            DriverTypeEnum::SQLITE => 'sqlite',
            DriverTypeEnum::MYSQL => 'mysql',
        };
        $auth = $config->user !== ''
            ? $config->user . ($config->password !== null && $config->password !== '' ? ':***' : '') . '@'
            : '';

        return sprintf('%s://%s%s:%d/%s', $scheme, $auth, $config->host, $config->port, $config->dbname);
    }

    public function isTenantSwitched(): bool
    {
        return $this->activeTenantIdentifier !== null;
    }

    public function onTenantSwitched(TenantSwitchedEvent $event): void
    {
        $this->tenantId = (string) $event->getTenantIdentifier();
        $this->activeTenantIdentifier = $event->getTenantIdentifier();
        $this->activeTenantConfig = $event->getTenantConfig();
    }

    public function reset(): void
    {
        $this->tenantId = null;
        $this->activeTenantIdentifier = null;
        $this->activeTenantConfig = null;
    }
}
