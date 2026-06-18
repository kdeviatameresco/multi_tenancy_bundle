<?php

namespace Hakam\MultiTenancyBundle\Command;

use Doctrine\Bundle\FixturesBundle\Command\LoadDataFixturesDoctrineCommand;
use Doctrine\Bundle\FixturesBundle\Loader\SymfonyFixturesLoader;
use Hakam\MultiTenancyBundle\Enum\DatabaseStatusEnum;
use Hakam\MultiTenancyBundle\Event\TenantBootstrappedEvent;
use Hakam\MultiTenancyBundle\Port\TenantDatabaseManagerInterface;
use Hakam\MultiTenancyBundle\Purger\TenantORMPurgerFactory;
use Hakam\MultiTenancyBundle\Services\TenantFixtureLoader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\ArrayInput;
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

        $doctrineFixturesCommand = new LoadDataFixturesDoctrineCommand(
            $this->fixturesLoader,
            $this->registry,
            $this->purgerFactories
        );

        $args = [
            '--append' => $input->getOption('append'),
            '--group' => $input->getOption('group'),
            '--purger' => $input->getOption('purger'),
            '--purge-exclusions' => $input->getOption('purge-exclusions'),
            '--purge-with-truncate' => $input->getOption('purge-with-truncate'),
            '--em' => 'tenant',
        ];

        $newInput = new ArrayInput($args);
        $newInput->setInteractive($input->isInteractive());

        $result = $doctrineFixturesCommand->run($newInput, $output);

        // Dispatch TenantBootstrappedEvent after successful fixture loading
        if ($result === 0) {
            $loadedFixtures = array_map(
                fn($fixture) => get_class($fixture),
                iterator_to_array($this->tenantFixtureLoader->getFixtures())
            );
            $this->eventDispatcher->dispatch(new TenantBootstrappedEvent(
                $dbId,
                null,
                $loadedFixtures
            ));
        }

        return $result;
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        foreach ($this->tenantFixtureLoader->getFixtures() as $fixture) {
            $this->fixturesLoader->addFixture($fixture);
        }
        $this->purgerFactories = [
            'tenant' => new TenantORMPurgerFactory(),
        ];
    }
}
