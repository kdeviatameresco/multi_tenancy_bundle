<?php

namespace Hakam\MultiTenancyBundle\Command;

use Doctrine\Bundle\FixturesBundle\Loader\SymfonyFixturesLoader;
use Doctrine\Bundle\FixturesBundle\Purger\ORMPurgerFactory;
use Doctrine\ORM\EntityManagerInterface;
use Hakam\MultiTenancyBundle\Enum\DatabaseStatusEnum;
use Hakam\MultiTenancyBundle\Event\TenantBootstrappedEvent;
use Hakam\MultiTenancyBundle\Executor\NonTransactionalORMExecutor;
use Hakam\MultiTenancyBundle\Port\TenantDatabaseManagerInterface;
use Hakam\MultiTenancyBundle\Purger\TenantORMPurgerFactory;
use Hakam\MultiTenancyBundle\Services\TenantFixtureLoader;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

#[AsCommand(name: 'tenant:fixtures:load', description: 'Load tenant fixtures', aliases: ['t:f:l'])]
class LoadTenantFixtureCommand  extends TenantCommand
{
    use CommandTrait;
    private  SymfonyFixturesLoader  $fixturesLoader;

    private  array $purgerFactories= [];

    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly ContainerInterface $container,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly TenantFixtureLoader $tenantFixtureLoader,
        private readonly TenantDatabaseManagerInterface $tenantDatabaseManager,
    ) {
        parent::__construct($registry, $container, $eventDispatcher);
        $this->fixturesLoader = new SymfonyFixturesLoader();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Load tenant fixtures into one tenant database, or into all migrated tenants when no dbId is given')
            ->addArgument('dbId', InputArgument::OPTIONAL, 'Tenant DB Identifier to load fixtures into. Omit to load into ALL migrated tenant databases.')
            ->addOption('append', null, InputOption::VALUE_NONE)
            ->addOption('group', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY)
            ->addOption('purger', null, InputOption::VALUE_REQUIRED, 'The purger to use for this command', 'tenant')
            ->addOption('purge-exclusions', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'List of database tables to ignore while purging')
            ->addOption('purge-with-truncate', null, InputOption::VALUE_NONE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dbId = $input->getArgument('dbId');

        // Single tenant when a dbId is given.
        if (null !== $dbId) {
            return $this->loadFixturesForTenant($dbId, $input, $output);
        }

        // No dbId: load fixtures into every migrated tenant database (mirrors
        // tenant:database:create --all and tenant:migrations:migrate init, which loop the
        // tenant list in the command rather than in a single switched connection).
        foreach ($this->tenantDatabaseManager->getTenantDbListByDatabaseStatus(DatabaseStatusEnum::DATABASE_MIGRATED) as $tenant) {
            if (0 !== $this->loadFixturesForTenant($tenant->identifier, $input, $output)) {
                return 1;
            }
        }

        return 0;
    }

    private function loadFixturesForTenant(mixed $dbId, InputInterface $input, OutputInterface $output): int
    {
        // Switch the tenant connection to this tenant before loading (dispatches SwitchDbEvent).
        $input->setArgument('dbId', $dbId);
        $this->getDependencyFactory($input);

        /** @var EntityManagerInterface $em */
        $em = $this->registry->getManager('tenant');

        $fixtures = $this->fixturesLoader->getFixtures($input->getOption('group'));
        if (!$fixtures) {
            $output->writeln('<error>Could not find any tenant fixture services to load.</error>');

            return 1;
        }

        $purgerName = $input->getOption('purger');
        $factory = $this->purgerFactories[$purgerName] ?? new ORMPurgerFactory();
        $purger = $factory->createForEntityManager(
            'tenant',
            $em,
            $input->getOption('purge-exclusions'),
            (bool) $input->getOption('purge-with-truncate'),
        );

        // Non-transactional executor: tenant provisioning fixtures may run DDL (CREATE/ALTER TABLE),
        // which auto-commits in MySQL and would break the wrapping transaction the stock ORMExecutor
        // opens. See NonTransactionalORMExecutor.
        $executor = new NonTransactionalORMExecutor($em, $purger);
        $executor->setLogger(new class($output) extends AbstractLogger {
            public function __construct(private OutputInterface $output)
            {
            }

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->output->writeln(sprintf('  <comment>></comment> <info>%s</info>', $message));
            }
        });

        $executor->execute($fixtures, (bool) $input->getOption('append'));

        // Dispatch TenantBootstrappedEvent after successful fixture loading.
        $loadedFixtures = array_map(
            fn($fixture) => get_class($fixture),
            iterator_to_array($this->tenantFixtureLoader->getFixtures())
        );
        $this->eventDispatcher->dispatch(new TenantBootstrappedEvent(
            $dbId,
            null,
            $loadedFixtures
        ));

        return 0;
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        // Pre-register every fixture before resolving dependencies. SymfonyFixturesLoader::addFixture()
        // (singular) resolves a fixture's DependentFixtureInterface deps immediately, so adding fixtures
        // one at a time throws when a fixture is added before its dependency exists in the loader.
        // addFixtures() (plural) does the two-pass the bundle intends: register all, then resolve deps.
        $fixtures = [];
        foreach ($this->tenantFixtureLoader->getFixtures() as $fixture) {
            $fixtures[] = ['fixture' => $fixture, 'groups' => []];
        }
        $this->fixturesLoader->addFixtures($fixtures);

        $this->purgerFactories = [
            'tenant' => new TenantORMPurgerFactory(),
        ];
    }
}
