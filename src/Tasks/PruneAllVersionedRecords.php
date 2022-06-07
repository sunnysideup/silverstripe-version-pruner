<?php

namespace Sunnysideup\VersionPruner\Api;

use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;

class PruneAllVersionedRecords extends BuildTask
{
    /**
     * @var int
     */
    protected const MAX_ITEMS_PER_CLASS = 500;

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

    /**
     * Prune all published DataObjects which are published according to config.
     *
     * @param mixed $request
     */
    public function run($request)
    {
        $classes = $this->getAllVersionedDataClasses();
        if($request->requestVar('verbose')) {
            $this->verbose = $request->requestVar('verbose');
        }
        if($request->requestVar('dry')) {
            $this->dryRun = $request->requestVar('dry');
        }
        if($request->requestVar('limit')) {
            $this->limit = $request->requestVar('limit');
        }
        DB::alteration_message('Pruning all DataObjects with a maximum of ' . self::MAX_ITEMS_PER_CLASS . ' per class.');
        $totalTotalDeleted = 0;
        $runObject = RunForOneObject::inst()
            ->setVerbose($this->verbose)
            ->setDryRun($this->dryRun);
        foreach ($classes as $className) {
            DB::alteration_message('... Looking at ' . $className);
            $objects = $this->getObjectsPerClassName($className);
            $totalDeleted = 0;

            foreach ($objects as $object) {
                // check if stages are present
                // DB::alteration_message('... ... Checking #ID: ' . $object->ID);
                $totalDeleted += $runObject->deleteSuperfluousVersions($object, false);
            }

            if ($totalDeleted > 0) {
                DB::alteration_message('... ... Deleted ' . $totalDeleted . ' records');
                $totalTotalDeleted += $totalDeleted;
            }
        }

        DB::alteration_message('Completed, pruned ' . $totalTotalDeleted . ' records');
    }

    protected function getObjectsPerClassName(string $className): DataList
    {
        return Versioned::get_by_stage($className, Versioned::DRAFT)
            ->sort(DB::get_conn()->random() . ' ASC')
            ->limit($this->limit)
        ;
    }

    /**
     * Get all versioned database classes.
     */
    private function getAllVersionedDataClasses(): array
    {
        $allClasses = ClassInfo::subclassesFor(DataObject::class);
        $versionedClasses = [];
        foreach ($allClasses as $className) {
            if (DataObject::has_extension($className, Versioned::class)) {
                $ancestors = ClassInfo::ancestry($className);
                foreach ($ancestors as $classNameInner) {
                    if (DataObject::has_extension($classNameInner, Versioned::class)) {
                        $versionedClasses[$classNameInner] = $classNameInner;

                        continue 2;
                    }
                }

                $versionedClasses[$className] = $className;
            }
        }

        return $versionedClasses;
    }
}
