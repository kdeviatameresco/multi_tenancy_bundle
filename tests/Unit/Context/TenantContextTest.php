<?php

declare(strict_types=1);

namespace Hakam\MultiTenancyBundle\Tests\Unit\Context;

use Hakam\MultiTenancyBundle\Config\TenantConnectionConfigDTO;
use Hakam\MultiTenancyBundle\Context\TenantContext;
use Hakam\MultiTenancyBundle\Enum\DatabaseStatusEnum;
use Hakam\MultiTenancyBundle\Enum\DriverTypeEnum;
use Hakam\MultiTenancyBundle\Event\TenantSwitchedEvent;
use PHPUnit\Framework\TestCase;

class TenantContextTest extends TestCase
{
    public function testGetTenantIdReturnsNullByDefault(): void
    {
        $context = new TenantContext();

        $this->assertNull($context->getTenantId());
    }

    public function testOnTenantSwitchedSetsTenantId(): void
    {
        $context = new TenantContext();

        $event = $this->createMock(TenantSwitchedEvent::class);
        $event->method('getTenantIdentifier')->willReturn('tenant_42');

        $context->onTenantSwitched($event);

        $this->assertSame('tenant_42', $context->getTenantId());
    }

    public function testResetClearsTenantId(): void
    {
        $context = new TenantContext();

        $event = $this->createMock(TenantSwitchedEvent::class);
        $event->method('getTenantIdentifier')->willReturn('tenant_42');
        $context->onTenantSwitched($event);

        $this->assertSame('tenant_42', $context->getTenantId());

        $context->reset();

        $this->assertNull($context->getTenantId());
    }

    public function testOnTenantSwitchedCastsIdentifierToString(): void
    {
        $context = new TenantContext();

        $event = $this->createMock(TenantSwitchedEvent::class);
        $event->method('getTenantIdentifier')->willReturn(123);

        $context->onTenantSwitched($event);

        $this->assertSame('123', $context->getTenantId());
    }

    public function testIsTenantSwitchedReportsLifecycle(): void
    {
        $context = new TenantContext();
        $this->assertFalse($context->isTenantSwitched());

        $context->onTenantSwitched($this->buildSwitchEvent(42));
        $this->assertTrue($context->isTenantSwitched());

        $context->reset();
        $this->assertFalse($context->isTenantSwitched());
    }

    public function testGetActiveTenantIdentifierPreservesOriginalType(): void
    {
        $context = new TenantContext();
        $context->onTenantSwitched($this->buildSwitchEvent(123));

        $this->assertSame(123, $context->getActiveTenantIdentifier(), 'Identifier type (int) must round-trip');
        $this->assertSame('123', $context->getTenantId(), 'getTenantId stringifies for backward compatibility');
    }

    public function testGetActiveDsnReconstructsFromConfig(): void
    {
        $context = new TenantContext();
        $context->onTenantSwitched($this->buildSwitchEvent(
            identifier: 11,
            host: 'tenant-rds-us-east-1.acme.local',
            port: 3306,
            dbname: 'test_db123',
            user: 'app_user',
            password: 'secret',
            driver: DriverTypeEnum::MYSQL,
        ));

        $this->assertSame(
            'mysql://app_user:***@tenant-rds-us-east-1.acme.local:3306/test_db123',
            $context->getActiveDsn(),
            'Reconstructed DSN must mask password and use mysql scheme',
        );
    }

    public function testGetActiveDsnUsesPgsqlSchemeForPostgresDriver(): void
    {
        $context = new TenantContext();
        $context->onTenantSwitched($this->buildSwitchEvent(
            identifier: 1,
            host: 'pg.local',
            port: 5432,
            dbname: 'tenant_db',
            user: 'postgres',
            password: null,
            driver: DriverTypeEnum::POSTGRES,
        ));

        $this->assertSame('pgsql://postgres@pg.local:5432/tenant_db', $context->getActiveDsn());
    }

    public function testGetActiveDsnReturnsNullWhenNoTenantActive(): void
    {
        $context = new TenantContext();
        $this->assertNull($context->getActiveDsn());
    }

    public function testResetClearsAllAccessorState(): void
    {
        $context = new TenantContext();
        $context->onTenantSwitched($this->buildSwitchEvent(42));

        $context->reset();

        $this->assertNull($context->getActiveTenantIdentifier());
        $this->assertNull($context->getActiveTenantConfig());
        $this->assertNull($context->getActiveDsn());
        $this->assertFalse($context->isTenantSwitched());
    }

    private function buildSwitchEvent(
        mixed $identifier,
        string $host = 'localhost',
        int $port = 3306,
        string $dbname = 'tenant_db',
        string $user = 'root',
        ?string $password = null,
        DriverTypeEnum $driver = DriverTypeEnum::MYSQL,
    ): TenantSwitchedEvent {
        $config = TenantConnectionConfigDTO::fromArgs(
            identifier: $identifier,
            driver: $driver,
            dbStatus: DatabaseStatusEnum::DATABASE_CREATED,
            host: $host,
            port: $port,
            dbname: $dbname,
            user: $user,
            password: $password,
        );

        return new TenantSwitchedEvent($identifier, $config);
    }
}
