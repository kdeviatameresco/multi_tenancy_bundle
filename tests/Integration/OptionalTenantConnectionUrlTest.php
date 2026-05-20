<?php

namespace Hakam\MultiTenancyBundle\Tests\Integration;

use Hakam\MultiTenancyBundle\Port\TenantConfigProviderInterface;
use Hakam\MultiTenancyBundle\Test\InMemoryTenantConfigProvider;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * When a custom TenantConfigProviderInterface is registered, the bundle should
 * accept an omitted tenant_connection.url and quietly substitute a placeholder
 * for the DBAL bootstrap connection. The per-tenant DSN comes from the provider
 * at runtime, so the bootstrap URL is never actually dialed.
 */
class OptionalTenantConnectionUrlTest extends IntegrationTestCase
{
    protected function getKernelConfig(): array
    {
        return [
            'tenant_config_provider' => 'test.in_memory_config_provider',
            // Intentionally omit tenant_connection.url. Override the kernel's
            // baseline tenant_connection with only driver/charset.
            'tenant_connection' => [
                'driver' => 'pdo_sqlite',
                'charset' => 'utf8',
            ],
        ];
    }

    protected function getServiceRegistrar(): ?callable
    {
        return function (ContainerBuilder $container): void {
            $container->register('test.in_memory_config_provider', InMemoryTenantConfigProvider::class)
                ->setPublic(true);
        };
    }

    public function testKernelBootsWithoutTenantConnectionUrl(): void
    {
        $provider = $this->getContainer()->get(TenantConfigProviderInterface::class);
        $this->assertInstanceOf(InMemoryTenantConfigProvider::class, $provider);

        $credentials = $this->getContainer()->getParameter('hakam.tenant_db_credentials');
        $this->assertIsArray($credentials);
        $this->assertArrayHasKey('db_url', $credentials);
        $this->assertNotEmpty(
            $credentials['db_url'],
            'Bundle should substitute a placeholder URL when a custom provider is registered and url is omitted',
        );
    }

    public function testPlaceholderUrlIsNotConfusableWithRealConfig(): void
    {
        $credentials = $this->getContainer()->getParameter('hakam.tenant_db_credentials');
        $this->assertStringContainsString(
            'placeholder',
            $credentials['db_url'],
            'Placeholder DSN should be self-identifying so it never gets mistaken for a real config in error messages',
        );
    }
}
