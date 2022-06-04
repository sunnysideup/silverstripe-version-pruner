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


class RunForOneObject
{

    protected $object = null;

    protected $baseTable = '';

    protected $toDelete = [];

    public function __construct($object)
    {
        $this->object = $object;
    }



    /**
     * Cached Config::inst()
     *
     * @var mixed
     */
    private $_conf = false;

    /**
     * returns the total number deleted
     *
     * @return int
     */
    public function doVersionCleanup() : int
    {
        $this->setStage();

        // array of version IDs to delete
        $this->toDelete[$this->getUniqueKey()] = [];

        // Base table has Versioned data
        $this->baseTable = $this->object->baseTable();

        $totalDeleted = 0;

        $keepVersions = $this->_config('keep_versions');
        if (is_int($keepVersions) && $keepVersions > 0) {


            $this->loadOlderVesionsForDeletion();

            if ($this->baseTable == 'SiteTree'
                && $this->_config('keep_redirects')
            ) {

            }
        }

        $this->loadDraftsForDeletion();

        if (!count($this->toDelete[$this->getUniqueKey()])) {
            return;
        }

        // Ugly (borrowed from DataObject::class), but returns all
        // database tables relating to DataObject
        $srcQuery = DataList::create($this->object->ClassName)
            ->filter('ID', $this->object->ID)
            ->dataQuery()
            ->query();
        $queriedTables = $srcQuery->queriedTables();

        foreach ($queriedTables as $table) {
            $delSQL = sprintf(
                'DELETE FROM "%s_Versions"
                    WHERE "Version" IN (%s)
                    AND "RecordID" = %d',
                $table,
                implode(',', $this->toDelete[$this->getUniqueKey()]),
                $this->object->ID
            );

            DB::query($delSQL);

            $totalDeleted += DB::affected_rows();
        }

        return $totalDeleted;
    }

    protected function getUniqueKey() : string
    {
        return $this->object->ClassName . '_' . $this->Object->ID;
    }


    protected function loadOlderVesionsForDeletion()
    {
        $query = new SQLSelect();
        $query->setSelect(['ID', 'Version', 'LastEdited']);
        $query->setFrom($this->baseTable . '_Versions');
        $query->addWhere(
            [
                '"RecordID" = ?'     => $this->object->ID,
                '"WasPublished" = ?' => 1,
            ]
        );
        if ($this->baseTable === 'SiteTree' && $this->_config('keep_redirects')) {
            $query->addWhere(
                [
                    '"URLSegment" = ?' => $this->object->URLSegment,
                    '"ParentID" = ?'   => $this->object->ParentID,
                ]
            );
        }
        $query->setOrderBy('ID DESC');

        //starting from "keepVersions" - going backwards in time
        $query->setLimit(999999, $keepVersions);

        $results = $query->execute();

        foreach ($results as $result) {
            array_push($this->toDelete[$this->getUniqueKey()], $result['Version']);
        }

    }

    protected function loadDraftsForDeletion()
    {

        $keepDrafts = $this->_config('keep_drafts');

        // remove drafts keeping `keep_drafts`
        if (is_int($keepDrafts) && $keepDrafts > 0) {
            $query = new SQLSelect();
            $query->setSelect(['ID', 'Version', 'LastEdited']);
            $query->setFrom($this->baseTable . '_Versions');
            $query->addWhere(
                'RecordID = ' . $this->object->ID,
                'WasPublished = 0'
            );
            $query->setOrderBy('ID DESC');

            //todo: check limit!
            $query->setLimit($keepDrafts, 0);

            $results = $query->execute();

            foreach ($results as $result) {
                array_push($this->toDelete[$this->getUniqueKey()], $result['Version']);
            }
        }
    }

    /**
     * Return a config variable
     *
     * @param string $key Config key
     *
     * @return mixed
     */
    private function _config(string $key)
    {
        if (!$this->_conf) {
            $this->_conf = Config::inst();
        }

        return $this->_conf->get(
            $this->object->ClassName,
            $key
        );
    }



    protected function setStage()
    {
        $oldMode = Versioned::get_reading_mode();
        if ($oldMode != 'Stage.Stage') {
            Versioned::set_reading_mode('Stage.Stage');
        }
        $has_stages = $this->object->hasStages();
        if ($oldMode != 'Stage.Stage') {
            Versioned::set_reading_mode($oldMode);
        }

        if (!$has_stages) {
            return;
        }
    }

}
