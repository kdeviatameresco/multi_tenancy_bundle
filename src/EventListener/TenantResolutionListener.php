<?php

namespace Hakam\MultiTenancyBundle\EventListener;

use Hakam\MultiTenancyBundle\Event\SwitchDbEvent;
use Hakam\MultiTenancyBundle\Exception\TenantResolutionException;
use Hakam\MultiTenancyBundle\Port\TenantResolverInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Listens to kernel.request events and automatically resolves the tenant.
 *
 * Registration is handled by HakamMultiTenancyExtension, which owns the
 * event listener tag (including priority) so it can be driven from bundle
 * configuration.
 *
 * @author Ramy Hakam <pencilsoft1@gmail.com>
 */
class TenantResolutionListener
{
    public const REQUEST_ATTRIBUTE_TENANT = '_tenant';
    public const REQUEST_ATTRIBUTE_TENANT_RESOLVED = '_tenant_resolved';

    public function __construct(
        private readonly TenantResolverInterface $resolver,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly bool $throwOnMissing = false,
        private readonly array $excludedPaths = [],
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if ($request->attributes->get(self::REQUEST_ATTRIBUTE_TENANT_RESOLVED, false)) {
            return;
        }

        $path = $request->getPathInfo();
        foreach ($this->excludedPaths as $excludedPath) {
            if (str_starts_with($path, $excludedPath)) {
                $request->attributes->set(self::REQUEST_ATTRIBUTE_TENANT_RESOLVED, true);
                return;
            }
        }

        $request->attributes->set(self::REQUEST_ATTRIBUTE_TENANT_RESOLVED, true);

        if (!$this->resolver->supports($request)) {
            if ($this->throwOnMissing) {
                throw TenantResolutionException::unsupportedRequest($request, $this->resolver::class);
            }
            return;
        }

        $tenantId = $this->resolver->resolve($request);

        if ($tenantId === null) {
            if ($this->throwOnMissing) {
                throw TenantResolutionException::identifierMissing($request, $this->resolver::class);
            }
            return;
        }

        $request->attributes->set(self::REQUEST_ATTRIBUTE_TENANT, $tenantId);

        $this->eventDispatcher->dispatch(new SwitchDbEvent($tenantId));
    }
}
