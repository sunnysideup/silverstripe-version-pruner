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

use Sunnysideup\VersionPruner\Api\RunForOneObject;


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
     * Prune all published DataObjects which are published according to config
     *
     * @return void
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
}
