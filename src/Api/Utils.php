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


class Utils
{




    /**
     * Prune all published DataObjects which are published according to config
     *
     * @return void
     */
    private function _prune()
    {
        $classes = $this->_getAllVersionedDataClasses();

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
     * Prune versions of deleted files/folders
     *
     * @return HTTPResponse
     */
    private function _pruneDeletedFileVersions()
    {
        DB::alteration_message('Pruning all deleted File DataObjects');

        $query = new SQLSelect();
        $query->setSelect(['RecordID']);
        $query->setFrom('File_Versions');
        $query->addWhere(
            [
                '"WasDeleted" = ?' => 1,
            ]
        );

        $to_delete = [];

        $results = $query->execute();

        foreach ($results as $result) {
            array_push($to_delete, $result['RecordID']);
        }

        if (!count($to_delete)) {
            DB::alteration_message('Completed, pruned 0 File records');

            return;
        }

        $deleteSQL = sprintf(
            'DELETE FROM File_Versions WHERE "RecordID" IN (%s)',
            implode(',', $to_delete)
        );

        DB::query($deleteSQL);

        $deleted = DB::affected_rows();

        DB::alteration_message('Completed, pruned ' . $deleted . ' File records');
    }

    /**
     * Delete all previous records of published records
     *
     * @return HTTPResponse
     */
    private function _reset()
    {
        DB::alteration_message('Pruning all published records');

        $classes = $this->_getAllVersionedDataClasses();

        $total = 0;

        foreach ($classes as $class) {
            $records = Versioned::get_by_stage($class, Versioned::DRAFT);
            $deleted = 0;

            // set to minimum
            $class::config()->set('keep_versions', 1);
            $class::config()->set('keep_drafts', 0);
            $class::config()->set('keep_redirects', false);

            foreach ($records as $r) {
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

        $this->_pruneDeletedFileVersions();
    }

    /**
     * Get all versioned database classes
     *
     * @return array
     */
    private function _getAllVersionedDataClasses()
    {
        $all_classes       = ClassInfo::subclassesFor(DataObject::class);
        $versioned_classes = [];
        foreach ($all_classes as $c) {
            if (DataObject::has_extension($c, Versioned::class)) {
                array_push($versioned_classes, $c);
            }
        }

        return array_reverse($versioned_classes);
    }


}
