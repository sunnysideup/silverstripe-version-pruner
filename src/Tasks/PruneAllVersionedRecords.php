<?php

namespace Sunnysideup\VersionPruner\Api;

use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;

class PruneAllVersionedRecords extends BuildTask
{
    /**
     * @var string
     */
    protected $title = 'Prune all versioned records';

    protected $description = 'Go through all dataobjects that are versioned and prune them as per schema provided.';

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
        DB::alteration_message('Pruning all DataObjects');
        $totalTotalDeleted = 0;
        $runObject = new RunForOneObject();
        foreach ($classes as $className) {
            DB::alteration_message('... Looking at ' . $className);
            $objects = Versioned::get_by_stage($className, Versioned::DRAFT);
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
