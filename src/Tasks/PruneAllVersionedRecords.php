<?php
namespace Sunnysideup\VersionPruner\Api;


use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\Versioned\Versioned;


use Axllent\VersionTruncator\VersionTruncator;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Dev\BuildTask;


class PruneAllVersionedRecords extends BuildTask
{

    /**
     * Prune all published DataObjects which are published according to config
     *
     * @return void
     */
    private function run($request)
    {
        $classes = $this->getAllVersionedDataClasses();

        DB::alteration_message('Pruning all DataObjects');

        $total = 0;

        foreach ($classes as $class) {
            $records = Versioned::get_by_stage($class, Versioned::DRAFT);
            $deleted = 0;

            foreach ($records as $r) {
                // check if stages are present
                if (!$r->hasStages()) {
                    continue;
                }

                if ($r->isLiveVersion()) {
                    $deleted += $r->doVersionCleanup();
                }
            }

            if ($deleted > 0) {
                DB::alteration_message(
                    'Deleted ' . $deleted . ' versioned ' . $class . ' records'
                );

                $total += $deleted;
            }
        }

        DB::alteration_message('Completed, pruned ' . $total . ' records');
    }

    /**
     * Get all versioned database classes
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
