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

class Drafts extends PruningTemplatesTemplate
{

    private static $keepDrafts = 10;

    public function setKeepDrafts(int $keepDrafts) : self
    {
        $this->keepDrafts = $keepDrafts;
        return $this;
    }

    protected function run()
    {

        // remove drafts keeping `keep_drafts`
        if ($keepDrafts > 0) {
            $query = $this->getBaseQuery();
            $query->addWhere(
                'RecordID = ' . $this->object->ID,
                'WasPublished = 0'
            );

            //todo: check limit!
            $query->setLimit($keepDrafts, 0);

            $this->toDelete[$this->getUniqueKey()] = $this->addVersionNumberToArray(
                $this->toDelete[$this->getUniqueKey()],
                $query->execute()
            );
        }
    }
}
