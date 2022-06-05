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

class BasedOnTimeScale extends PruningTemplatesTemplate
{

    protected $timeScale = [
        'Minutes' => 15,
        'Hours' => 12,
        'Days' => 7,
        'Weeks' => 12,
        'Months' => 24,
        'Years' => 7,
    ];


    public function setTimeScale(array $timeScale) : self
    {
        $this->timeScale = $timeScale;

        return $this;
    }

    public function run()
    {
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


    protected function buildTimeScalePattern()
    {

    }


}
