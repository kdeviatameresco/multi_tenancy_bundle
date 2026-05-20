<?php

namespace Hakam\MultiTenancyBundle\Exception;

/**
 * Thrown by TenantConfigProviderInterface implementations when the requested
 * tenant identifier has no registered configuration. Distinct from
 * TenantResolutionException (which is about resolving a request to an
 * identifier) — this is about looking up a known identifier's config.
 */
class TenantNotFoundException extends MultiTenancyException
{
    public static function forIdentifier(mixed $identifier, array $knownIdentifiers = []): self
    {
        $known = $knownIdentifiers === []
            ? 'no tenants are registered'
            : 'known identifiers: ' . implode(', ', array_map(static fn ($id) => var_export($id, true), $knownIdentifiers));

        return new self(sprintf(
            'No tenant configuration registered for identifier %s (%s).',
            var_export($identifier, true),
            $known,
        ));
    }
}
