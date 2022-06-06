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
    private static $segment = 'prunes-all-versioned-records';

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

        foreach ($classes as $className) {
            $objects = Versioned::get_by_stage($className, Versioned::DRAFT);
            $totalDeleted = 0;

            foreach ($objects as $object) {
                // check if stages are present
                $totalDeleted = (new RunForOneObject($object))->run();
            }

            if ($totalDeleted > 0) {
                DB::alteration_message(
                    'Deleted ' . $totalDeleted . ' versioned ' . $className . ' records'
                );

                $totalTotalDeleted += $totalDeleted;
            }
        }

        DB::alteration_message('Completed, pruned ' . $totalTotalDeleted . ' records');
    }

    /**
     * Get all versioned database classes.
     *
     * @return array
     */
    private function getAllVersionedDataClasses()
    {
        $allClasses = ClassInfo::subclassesFor(DataObject::class);
        $versionedClasses = [];
        foreach ($allClasses as $className) {
            if (DataObject::has_extension($className, Versioned::class)) {
                $versionedClasses[$className] = $className;
            }
        }

        return array_reverse($versionedClasses);
    }
}
