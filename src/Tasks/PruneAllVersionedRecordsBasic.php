<?php

namespace Sunnysideup\VersionPruner\Tasks;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;

class PruneAllVersionedRecordsBasic extends BuildTask
{
    /**
     * @var int
     */
    protected const MAX_ITEMS_PER_CLASS = 500;

    /**
     * @var string
     */
    protected $title = 'Basic SiteTree Prune of Older Records';

    protected $description = 'See getDescription method for more information.';

    /**
     * @var string
     */
    private static $segment = 'prune-all-versioned-records-sitetree-basic';

    private static $delete_older_than_strtotime_phrase = '-12 months';

    /**
     * Prune all published DataObjects which are published according to config.
     *
     * @param mixed $request
     */
    public function run($request)
    {
        $numberOfRecords = DB::query('SELECT COUNT(ID) FROM SiteTree_Versions')->value();
        $oldestRecord = DB::query('SELECT MIN(LastEdited) FROM SiteTree_Versions')->value();
        $newestRecord = DB::query('SELECT MAX(LastEdited) FROM SiteTree_Versions')->value();
        DB::alteration_message("BEFORE: Total pages: {$numberOfRecords}, oldest record: {$oldestRecord}, newest record: {$newestRecord}", 'created');

        $classTables = ['SiteTree'];
        $allClasses = ClassInfo::subclassesFor(SiteTree::class);
        foreach ($allClasses as $class) {
            if (DataObject::getSchema()->classHasTable($class)) {
                $classTables[] = DataObject::getSchema()->tableName($class);
            }
        }

        $classTables = array_unique($classTables);
        $beforeDate = date('Y-m-d', strtotime($this->Config()->get('delete_older_than_strtotime_phrase')));
        DB::alteration_message("Looking for all versions that are older than {$beforeDate}", 'created');
        foreach ($classTables as $classTable) {
            $tableName = $classTable . '_Versions';
            if ('SiteTree_Versions' === $tableName) {
                // first we delete the oldies
                $joinWhere = " \"LastEdited\" < date '{$beforeDate}'";
                $leftJoin = '';
            } else {
                // now we delete the non-linking ones
                $leftJoin = " LEFT JOIN SiteTree_Versions ON {$tableName}.RecordID = SiteTree_Versions.RecordID AND {$tableName}.Version = SiteTree_Versions.Version ";
                $joinWhere = ' SiteTree_Versions.RecordID IS NULL';
            }

            DB::alteration_message("DELETING ALL ENTRIES FROM {$tableName}");
            $sql = "DELETE {$tableName}.* FROM \"{$tableName}\" {$leftJoin} WHERE {$joinWhere};";
            DB::query($sql);
        }

        $numberOfRecords = DB::query('SELECT COUNT(ID) FROM SiteTree_Versions')->value();
        $oldestRecord = DB::query('SELECT MIN(LastEdited) FROM SiteTree_Versions')->value();
        $newestRecord = DB::query('SELECT MAX(LastEdited) FROM SiteTree_Versions')->value();
        DB::alteration_message("Total pages: {$numberOfRecords}, oldest record: {$oldestRecord}, newest record: {$newestRecord}", 'created');
    }

    /**
     * @return string HTML formatted description
     */
    public function getDescription()
    {
        return '
        Basic SiteTree prune of older records.
        This task will remove all versions of SiteTree records with a LastEdited date of: ' .
            $this->Config()->get('delete_older_than_strtotime_phrase') .
            ' or older.
        Up to ' . self::MAX_ITEMS_PER_CLASS . ' records will be deleted per run.
        For a more advanced approach, you can set up a template for a pruning service.';
    }
}
