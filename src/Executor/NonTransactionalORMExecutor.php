<?php

declare(strict_types=1);

namespace Hakam\MultiTenancyBundle\Executor;

use Doctrine\Common\DataFixtures\Executor\AbstractExecutor;
use Doctrine\Common\DataFixtures\Executor\ORMExecutorCommon;

/**
 * Like Doctrine's ORMExecutor, but loads fixtures WITHOUT wrapping them in a transaction.
 *
 * Tenant provisioning fixtures legitimately run DDL (CREATE/ALTER TABLE) — e.g. adding a tenant's
 * Asset Detail field columns. In MySQL, DDL triggers an implicit COMMIT, which terminates any open
 * transaction. The stock {@see \Doctrine\Common\DataFixtures\Executor\ORMExecutor::execute()} always
 * wraps the load in wrapInTransaction(), so a fixture that runs DDL leaves no active transaction and
 * the executor's final commit fails with "There is no active transaction" / "SAVEPOINT ... does not
 * exist". ORMExecutor is final, so it can't be subclassed to drop that wrapper.
 *
 * Running without an outer transaction lets each statement auto-commit, exactly as the application
 * does at runtime — neither AssetPlanner nor an API request wraps field creation in a transaction.
 * Intended for once-per-suite/baseline tenant loading where atomicity across the whole fixture set is
 * not required (a failed load is recovered by rebuilding the tenant databases from scratch).
 */
final class NonTransactionalORMExecutor extends AbstractExecutor
{
    use ORMExecutorCommon;

    /** @inheritDoc */
    public function execute(array $fixtures, bool $append = false): void
    {
        if ($append === false) {
            $this->purge();
        }

        foreach ($fixtures as $fixture) {
            $this->load($this->em, $fixture);
        }
    }
}
