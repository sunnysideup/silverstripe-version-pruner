<?php

namespace Sunnysideup\VersionPruner\Tasks;

use Override;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

class PruneAllVersionedRecordsBasic extends BuildTask
{
    /**
     * @var int
     */
    protected const MAX_ITEMS_PER_CLASS = 500;

    /**
     * @var string
     */
    protected static string $commandName = 'prune-all-versioned-records-sitetree-basic';

    /**
     * @var string
     */
    protected string $title = 'Basic SiteTree Prune of Older Records';

    /**
     * @var string
     */
    protected static string $description = 'Basic SiteTree prune of older records. This task will remove all versions of SiteTree records with a LastEdited date older than the configured threshold. Up to 500 records will be deleted per run. For a more advanced approach, you can set up a template for a pruning service.';

    private static $delete_older_than_strtotime_phrase = '-12 months';

    /**
     * Prune all published DataObjects which are published according to config.
     */
    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $numberOfRecords = DB::query('SELECT COUNT(ID) FROM SiteTree_Versions')->value();
        $oldestRecord = DB::query('SELECT MIN(LastEdited) FROM SiteTree_Versions')->value();
        $newestRecord = DB::query('SELECT MAX(LastEdited) FROM SiteTree_Versions')->value();
        $output->writeln(sprintf('BEFORE: Total pages: %s, oldest record: %s, newest record: %s', $numberOfRecords, $oldestRecord, $newestRecord));

        $classTables = ['SiteTree'];
        $allClasses = ClassInfo::subclassesFor(SiteTree::class);
        foreach ($allClasses as $class) {
            if (DataObject::getSchema()->classHasTable($class)) {
                $classTables[] = DataObject::getSchema()->tableName($class);
            }
        }

        $classTables = array_unique($classTables);
        $beforeDate = date('Y-m-d', strtotime((string) $this->Config()->get('delete_older_than_strtotime_phrase')));
        $output->writeln('Looking for all versions that are older than ' . $beforeDate);
        foreach ($classTables as $classTable) {
            $tableName = $classTable . '_Versions';
            if ('SiteTree_Versions' === $tableName) {
                // first we delete the oldies
                $joinWhere = sprintf(" \"LastEdited\" < date '%s'", $beforeDate);
                $leftJoin = '';
            } else {
                // now we delete the non-linking ones
                $leftJoin = sprintf(' LEFT JOIN SiteTree_Versions ON %s.RecordID = SiteTree_Versions.RecordID AND %s.Version = SiteTree_Versions.Version ', $tableName, $tableName);
                $joinWhere = ' SiteTree_Versions.RecordID IS NULL';
            }

            $output->writeln('DELETING ALL ENTRIES FROM ' . $tableName);
            $sql = sprintf('DELETE %s.* FROM "%s" %s WHERE %s;', $tableName, $tableName, $leftJoin, $joinWhere);
            DB::query($sql);
        }

        $numberOfRecords = DB::query('SELECT COUNT(ID) FROM SiteTree_Versions')->value();
        $oldestRecord = DB::query('SELECT MIN(LastEdited) FROM SiteTree_Versions')->value();
        $newestRecord = DB::query('SELECT MAX(LastEdited) FROM SiteTree_Versions')->value();
        $output->writeln(sprintf('Total pages: %s, oldest record: %s, newest record: %s', $numberOfRecords, $oldestRecord, $newestRecord));

        return Command::SUCCESS;
    }


}
