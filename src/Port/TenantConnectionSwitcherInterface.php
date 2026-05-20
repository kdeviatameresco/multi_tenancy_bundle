<?php

namespace Hakam\MultiTenancyBundle\Port;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Extension point for coordinating tenant database switches.
 *
 * The bundle ships TenantConnectionSwitcher as the default implementation,
 * which drives switches via DBAL 4 middleware and reflection on
 * Connection::$params. Implement this interface to substitute alternative
 * strategies — e.g. test isolation via per-tenant connections, multi-region
 * failover, or instrumentation wrappers.
 *
 * Extends ResetInterface so worker-mode runtimes (FrankenPHP, Roadrunner)
 * get a clean tenant state between requests via Symfony's services_resetter.
 */
interface TenantConnectionSwitcherInterface extends ResetInterface
{
    /**
     * Switch the tenant connection to use the given DBAL parameters.
     * Typical params: dbname, host, port, user, password.
     */
    public function switchConnection(array $params): void;

    /**
     * Clear any active tenant switch and restore the bootstrap state.
     */
    public function reset(): void;

    /**
     * The parameters last passed to switchConnection(), or null if no switch
     * is active.
     */
    public function getActiveOverrides(): ?array;

    /**
     * Convenience accessor for the active dbname, or null if not switched.
     */
    public function getActiveDatabaseName(): ?string;

    /**
     * Whether a tenant switch is currently active.
     */
    public function isSwitched(): bool;
}
