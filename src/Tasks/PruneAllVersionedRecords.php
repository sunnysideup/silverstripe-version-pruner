<?php

namespace Sunnysideup\VersionPruner\Tasks;

use Override;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use Sunnysideup\VersionPruner\Api\RunForOneObject;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

class PruneAllVersionedRecords extends BuildTask
{
    /**
     * the number of unique records in the LIVE / DRAFT Table
     * that will be looked at in one go.
     * @var int
     */
    protected const MAX_ITEMS_PER_CLASS = 10000;

    /**
     * @var string
     */
    protected static string $commandName = 'prune-all-versioned-records';

    /**
     * @var string
     */
    protected string $title = 'Prune all versioned records';

    /**
     * @var string
     */
    protected static string $description = 'Go through all dataobjects that are versioned and prune them as per schema provided.';

    protected $limit = self::MAX_ITEMS_PER_CLASS;

    protected $verbose = false;

    protected $dryRun = false;

    /**
     * Get CLI options for this task
     */
    #[Override]
    public function getOptions(): array
    {
        return [
            new InputOption('verbose', 'v', InputOption::VALUE_NONE, 'Enable verbose output'),
            new InputOption('dry', 'd', InputOption::VALUE_NONE, 'Perform a dry run without deleting records'),
            new InputOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Limit per class', self::MAX_ITEMS_PER_CLASS),
        ];
    }

    public function setVerbose(?bool $verbose = true): self
    {
        $this->verbose = $verbose;

        return $this;
    }

    public function setDryRun(?bool $dryRun = true): self
    {
        $this->dryRun = $dryRun;

        return $this;
    }

    public function setLimit(int $limit): self
    {
        $this->limit = $limit;

        return $this;
    }

    /**
     * Prune all published DataObjects which are published according to config.
     */
    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $classes = $this->getAllVersionedDataClasses();

        if ($input->getOption('verbose')) {
            $this->verbose = true;
        }

        if ($input->getOption('dry')) {
            $this->dryRun = true;
        }

        if ($input->getOption('limit')) {
            $this->limit = (int) $input->getOption('limit');
        }

        $output->writeln('Pruning all DataObjects with a maximum of ' . self::MAX_ITEMS_PER_CLASS . ' per class.');
        $totalTotalDeleted = 0;
        $runObject = RunForOneObject::inst()
            ->setVerbose($this->verbose)
            ->setDryRun($this->dryRun);
        $output->writeln('settings (set as parameters)');
        $output->writeln('-------------------- ');
        $output->writeln('verbose: ' . ($this->verbose ? 'yes' : 'no'));
        $output->writeln('dry run: ' . ($this->dryRun ? 'yes' : 'no'));
        $output->writeln('limit per class: ' . $this->limit);
        $output->writeln('-------------------- ');
        foreach ($classes as $className) {
            $objects = $this->getObjectsPerClassName($runObject, $className);
            $noData = '';
            if (! $objects->exists()) {
                $noData = '- nothing to do';
            }

            $output->writeln('... Looking at ' . $className . ' ' . $noData);
            $totalDeleted = 0;

            foreach ($objects as $object) {
                // check if stages are present
                if ($this->verbose) {
                    $output->writeln('... ... Checking #ID: ' . $object->ID);
                }

                $totalDeleted += $runObject->deleteSuperfluousVersions($object);
            }

            if ($totalDeleted > 0) {
                $output->writeln('... ... Deleted ' . $totalDeleted . ' version records');
                $totalTotalDeleted += $totalDeleted;
            }
        }

        $output->writeln('-------------------- ');
        $output->writeln('Completed, pruned ' . $totalTotalDeleted . ' version records');
        $output->writeln('-------------------- ');

        $array = $runObject->getCountRegister();
        foreach ($array as $table => $count) {
            $output->writeln('... ' . $table . ' has ' . $count . ' version records left.');
        }

        return Command::SUCCESS;
    }

    protected function getObjectsPerClassName($runObject, string $className): DataList
    {
        $rootTable = $runObject->getRootTable($className);
        $sql = '
            SELECT COUNT("ID") AS C, "RecordID"
            FROM "' . $rootTable . '_Versions"
            WHERE ClassName = \'' . addslashes($className) . '\'
            GROUP BY "RecordID"
            ORDER BY C DESC
            LIMIT ' . $this->limit . ';';
        $rows = DB::query($sql);
        $array = [-1 => 0];
        foreach ($rows as $row) {
            $array[] = $row['RecordID'];
        }

        return Versioned::get_by_stage($className, Versioned::DRAFT)
            ->filter(['ID' => $array])
            ->limit($this->limit);
    }

    /**
     * Get all versioned database classes.
     */
    protected function getAllVersionedDataClasses(): array
    {
        $allClasses = ClassInfo::subclassesFor(DataObject::class);
        $versionedClasses = [];
        foreach ($allClasses as $className) {
            if (DataObject::has_extension($className, Versioned::class)) {
                $versionedClasses[$className] = $className;
            }
        }

        return $versionedClasses;
    }
}
