<?php

namespace Hakam\MultiTenancyBundle\Command;

use Exception;
use Hakam\MultiTenancyBundle\Config\TenantConnectionConfigDTO;
use Hakam\MultiTenancyBundle\Enum\DatabaseStatusEnum;
use Hakam\MultiTenancyBundle\Exception\MultiTenancyException;
use Hakam\MultiTenancyBundle\Port\TenantDatabaseManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'tenant:database:drop',
    description: 'Proxy to drop a tenant database.',
)]
final class DropDatabaseCommand extends Command
{
    public function __construct(
        private readonly TenantDatabaseManagerInterface $tenantDatabaseManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Drop tenant databases')
            ->setAliases(['t:d:d'])
            ->addOption('dbid', 'd', InputOption::VALUE_REQUIRED, 'Drop the database for a specific tenant ID')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Drop all existing tenant databases')
            ->setHelp('Drops tenant databases. Use --dbid=<id> for a specific tenant, or --all to drop every existing tenant database. Dropping resets the tenant status to DATABASE_NOT_CREATED.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $dbId = $input->getOption('dbid');
            $all = $input->getOption('all');

            if ($dbId && $all) {
                $output->writeln('Cannot use --dbid and --all options together');
                return 1;
            }
            if ($dbId) {
                return $this->dropDatabaseById((int) $dbId, $output);
            }
            return $this->dropAllDatabases($output);
        } catch (Exception $e) {
            $output->writeln(sprintf('Failed to drop database: %s', $e->getMessage()));
            return 1;
        }
    }

    private function dropAllDatabases(OutputInterface $output): int
    {
        // Existing tenant databases = those created and/or migrated.
        try {
            $listOfDbs = array_merge(
                $this->tenantDatabaseManager->getTenantDbListByDatabaseStatus(DatabaseStatusEnum::DATABASE_MIGRATED),
                $this->tenantDatabaseManager->getTenantDbListByDatabaseStatus(DatabaseStatusEnum::DATABASE_CREATED),
            );
        } catch (Throwable $e) {
            // The tenant registry could not be read (e.g. the main database does not exist yet).
            // Treat as "nothing to drop" so this command is safe to run on a fresh setup.
            $output->writeln('No tenant databases to drop');
            return 0;
        }

        if (empty($listOfDbs)) {
            $output->writeln('No tenant databases to drop');
            return 0;
        }

        foreach ($listOfDbs as $db) {
            $this->dropDatabase($db, $output);
            $this->tenantDatabaseManager->updateTenantDatabaseStatus($db->identifier, DatabaseStatusEnum::DATABASE_NOT_CREATED);
            $output->writeln(sprintf('Database %s dropped successfully', $db->dbname));
        }
        $output->writeln('All tenant databases dropped successfully');
        return 0;
    }

    private function dropDatabaseById(int $dbId, OutputInterface $output): int
    {
        try {
            $dbConfig = $this->tenantDatabaseManager->getTenantDatabaseById($dbId);
            $this->dropDatabase($dbConfig, $output);
            $this->tenantDatabaseManager->updateTenantDatabaseStatus($dbId, DatabaseStatusEnum::DATABASE_NOT_CREATED);
            $output->writeln(sprintf('Database %s dropped successfully for tenant ID %d', $dbConfig->dbname, $dbId));
            return 0;
        } catch (Exception $e) {
            $output->writeln(sprintf('Failed to drop database for tenant ID %d: %s', $dbId, $e->getMessage()));
            return 1;
        }
    }

    private function dropDatabase(TenantConnectionConfigDTO $dbConfiguration, OutputInterface $output): bool
    {
        $dropped = $this->tenantDatabaseManager->dropTenantDatabase($dbConfiguration);
        if (!$dropped) {
            throw new MultiTenancyException(sprintf('Failed to drop database %s', $dbConfiguration->dbname));
        }
        return $dropped;
    }
}
