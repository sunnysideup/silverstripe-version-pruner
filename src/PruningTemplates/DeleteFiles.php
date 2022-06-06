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

class DeleteFiles extends PruningTemplatesTemplate
{
    /**
     * Prune versions of deleted files/folders
     *
     * @return HTTPResponse
     */
    private function run()
    {
        if($this->hasBeenDeleted()) {
            $query = $this->getBaseQuery();
            $query->addWhere(['"RecordID" = ?'     => $this->object->ID,]);
            //starting from "keepVersions" - going backwards in time
            $this->toDelete[$this->getUniqueKey()] = $this->addVersionNumberToArray(
                $this->toDelete[$this->getUniqueKey()],
                $query->execute()
            );
        }
    }

    protected function hasBeenDeleted() : bool
    {
        $query = new SQLSelect();
        $query->setSelect(['RecordID']);
        $query->addWhere(['"WasDeleted" = ?' => 1,'RecordID = ? ' => $this->object->ID]);
        $query->setLimit(1);

        $hasBeenDeleted = false;

        $results = $query->execute();

        foreach ($results as $result) {
            $hasBeenDeleted = true;
        }

        return $hasBeenDeleted;
    }


}
