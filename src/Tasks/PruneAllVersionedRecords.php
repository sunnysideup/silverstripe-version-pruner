<?php

namespace Sunnysideup\VersionPruner\Tasks;

use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use Sunnysideup\VersionPruner\Api\RunForOneObject;

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
    protected $title = 'Prune all versioned records';

    protected $description = 'Go through all dataobjects that are versioned and prune them as per schema provided.';

    protected $limit = self::MAX_ITEMS_PER_CLASS;

    protected $verbose = false;

    protected $dryRun = false;

    /**
     * @var string
     */
    private static $segment = 'prune-all-versioned-records';

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
     *
     * @param mixed $request
     */
    public function run($request)
    {
        $classes = $this->getAllVersionedDataClasses();
        if ($request && $request->requestVar('verbose')) {
            $this->verbose = $request->requestVar('verbose');
        }

        if ($request && $request->requestVar('dry')) {
            $this->dryRun = $request->requestVar('dry');
        }

        if ($request && $request->requestVar('limit')) {
            $this->limit = $request->requestVar('limit');
        }

        DB::alteration_message('Pruning all DataObjects with a maximum of ' . self::MAX_ITEMS_PER_CLASS . ' per class.');
        $totalTotalDeleted = 0;
        $runObject = RunForOneObject::inst()
            ->setVerbose($this->verbose)
            ->setDryRun($this->dryRun);
        DB::alteration_message('settings (set as parameters)');
        DB::alteration_message('-------------------- ');
        DB::alteration_message('verbose: ' . ($this->verbose ? 'yes' : 'no'), 'created');
        DB::alteration_message('dry run: ' . ($this->dryRun ? 'yes' : 'no'), 'created');
        DB::alteration_message('limit per class: ' . $this->limit, 'created');
        DB::alteration_message('-------------------- ');
        foreach ($classes as $className) {
            $objects = $this->getObjectsPerClassName($runObject, $className);
            $noData = '';
            if (! $objects->exists()) {
                $noData = '- nothing to do';
            }
            DB::alteration_message('... Looking at ' . $className . ' ' . $noData);
            $totalDeleted = 0;

            foreach ($objects as $object) {
                // check if stages are present
                if ($this->verbose) {
                    DB::alteration_message('... ... Checking #ID: ' . $object->ID);
                }
                $totalDeleted += $runObject->deleteSuperfluousVersions($object);
            }

            if ($totalDeleted > 0) {
                DB::alteration_message('... ... Deleted ' . $totalDeleted . ' version records');
                $totalTotalDeleted += $totalDeleted;
            }
        }

        DB::alteration_message('-------------------- ');
        DB::alteration_message('Completed, pruned ' . $totalTotalDeleted . ' version records');
        DB::alteration_message('-------------------- ');
        $array = $runObject->getCountRegister();
        foreach ($array as $table => $count) {
            DB::alteration_message('... ' . $table . ' has ' . $count . ' version records left.');
        }
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
