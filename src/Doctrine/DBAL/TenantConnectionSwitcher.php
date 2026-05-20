<?php

namespace Hakam\MultiTenancyBundle\Doctrine\DBAL;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Coordinates dynamic tenant database switching via DBAL middleware.
 *
 * Replaces the old TenantConnection (wrapper_class) approach that was
 * incompatible with DBAL 4.
 */
final class TenantConnectionSwitcher implements ResetInterface
{
    private readonly \ReflectionProperty $paramsProperty;
    private readonly array $initialParams;
    private ?array $activeOverrides = null;

    public function __construct(
        private readonly Connection $tenantConnection,
        private readonly TenantDriverMiddleware $middleware,
    ) {
        $this->paramsProperty = new \ReflectionProperty(Connection::class, 'params');
        $this->initialParams = $this->paramsProperty->getValue($this->tenantConnection);
    }

    /**
     * Switch the tenant connection to use new parameters.
     *
     * Updates the middleware override params (used by TenantDriver on reconnect),
     * syncs Connection::$params so that getDatabase() returns the correct value,
     * then closes the connection to trigger a lazy reconnect.
     */
    public function switchConnection(array $params): void
    {
        $this->middleware->setOverrideParams($params);
        $this->activeOverrides = $params;

        $this->paramsProperty->setValue(
            $this->tenantConnection,
            array_merge($this->initialParams, $params),
        );

        $this->tenantConnection->close();
    }

    /**
     * Clear any active tenant switch and restore the connection to its
     * initial bootstrap state. Tagged kernel.reset so worker-mode runtimes
     * (FrankenPHP, Roadrunner) get a clean slate between requests.
     */
    public function reset(): void
    {
        if ($this->activeOverrides === null) {
            return;
        }

        $this->middleware->setOverrideParams(null);
        $this->paramsProperty->setValue($this->tenantConnection, $this->initialParams);
        $this->tenantConnection->close();
        $this->activeOverrides = null;
    }

    public function getActiveOverrides(): ?array
    {
        return $this->activeOverrides;
    }

    public function getActiveDatabaseName(): ?string
    {
        return $this->activeOverrides['dbname'] ?? null;
    }

    public function isSwitched(): bool
    {
        return $this->activeOverrides !== null;
    }
}
