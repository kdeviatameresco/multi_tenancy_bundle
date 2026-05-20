<?php

namespace Hakam\MultiTenancyBundle\Exception;

use Symfony\Component\HttpFoundation\Request;

/**
 * Thrown by TenantResolutionListener when automatic tenant resolution fails
 * and `throw_on_missing` is set. Use this type to catch tenant-resolution
 * failures specifically — distinct from generic runtime errors.
 */
class TenantResolutionException extends MultiTenancyException
{
    public static function unsupportedRequest(Request $request, string $resolverClass): self
    {
        return new self(sprintf(
            'Tenant resolution failed: %s does not support %s %s (host=%s). Check that the request matches the resolver\'s expected shape (header, subdomain, path segment, etc.) or add the path to resolver.excluded_paths.',
            self::shortClassName($resolverClass),
            $request->getMethod(),
            $request->getPathInfo(),
            $request->getHost(),
        ));
    }

    public static function identifierMissing(Request $request, string $resolverClass): self
    {
        return new self(sprintf(
            'Tenant resolution failed: %s could not extract a tenant identifier from %s %s (host=%s). The resolver claimed to support the request but returned null — verify the source (e.g. X-Tenant-ID header value, subdomain segment, path segment) is present and non-empty.',
            self::shortClassName($resolverClass),
            $request->getMethod(),
            $request->getPathInfo(),
            $request->getHost(),
        ));
    }

    private static function shortClassName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }
}
