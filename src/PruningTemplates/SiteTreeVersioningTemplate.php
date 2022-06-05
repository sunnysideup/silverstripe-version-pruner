<?php
namespace Sunnysideup\VersionPruner\PruningTemplates;

use Sunnysideup\VersionPruner\PruningTemplatesTemplate;


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

class SiteTreeVersioningTemplate extends PruningTemplatesTemplate
{

    protected $keepVersions = 10;

    protected $fieldsWithChangesToKeep = [
        'URLSegment',
        'ParentID',
    ];

    public function setKeepVersions(int $keepVersions) : self
    {
        $this->keepVersions = $keepVersions;

        return $this;
    }

    public function run()
    {
        $this->markOlderItemsWithTheSameKeyValues();
        $this->keepOneOfEachOlderItemWithDifferentKeyValues();

    }

    protected function markOlderItemsWithTheSameKeyValues()
    {
        $query = $this->getBaseQuery();
        $filter = [
            '"RecordID" = ?'     => $this->object->ID,
            '"WasPublished" = ?' => 1,
        ];

        foreach($this->fieldsWithChangesToKeep as $field) {
            $filter['"'.$field.'" = ?'] = $this->object->{$field};
        }
        $query->addWhere($filter);

        //starting from "keepVersions" - going backwards in time
        $query->setLimit(999999, $this->keepVersions);

        $this->toDelete[$this->getUniqueKey()] = $this->addVersionNumberToArray(
            $this->toDelete[$this->getUniqueKey()],
            $query->execute()
        );
    }

    protected function keepOneOfEachOlderItemWithDifferentKeyValues()
    {
        $toKeep = $this->markItemsToKeep();
        $query = $this->getBaseQuery($this->fieldsWithChangesToKeep);
        $orFilterKey = '"'.implode('" != ? OR "', $this->fieldsWithChangesToKeep).'" != ?';
        $orFilterValuesArray = [];
        foreach($this->fieldsWithChangesToKeep as $field) {
            $orFilterValuesArray[] = $this->object->{$field};
        }


        $query->addWhere(
            [
                '"RecordID" = ?'                       => $this->object->ID,
                '"WasPublished" = ?'                   => 1,
                '"Version" NOT IN (' . implode(',', $toKeep) . ')',
                $orFilterKey                           => $orFilterValuesArray,
            ]
        );

        $results = $query->execute();

        $changedRecords = [];

        // create a `ParentID - $URLSegment` array to keep only a single
        // version of each for URL redirection
        foreach ($results as $result) {
            $keyArray[] = [];
            foreach($this->fieldsWithChangesToKeep as $field) {
                $keyArray[] = $result[$field];
            }
            $key = implode('_', $keyArray);

            if (! (in_array($key, $changedRecords))) {
                //mark the first one, but do not mark it to delete
                array_push($changedRecords, $key);
            } else {
                // the first one has been done, so we can delete others...
                $this->toDelete[$this->getUniqueKey()][$result['Version']] = $result['Version'];
            }
        }
    }

    protected function markItemsToKeep() : array
    {

        // Get the most recent Version IDs of all published pages to ensure
        // we leave at least X versions even if a URLSegment or ParentID
        // has changed.
        $query = $this->getBaseQuery();
        $query->addWhere(
            [
                '"RecordID" = ?'     => $this->object->ID,
                '"WasPublished" = ?' => 1,
            ]
        );

        //todo: check limit
        $query->setLimit($this->keepVersions, 0);

        return $this->addVersionNumberToArray(
            [],
            $query->execute()
        );
    }

}
