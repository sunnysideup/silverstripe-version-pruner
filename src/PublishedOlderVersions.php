<?php
namespace Sunnysideup\VersionPruner\Templates;

use Sunnysideup\VersionPruner\TruncateTemplate;


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

class PublishedOlderVersions extends TruncateTemplate
{


    private static $keep_versions = 50;


    protected function run()
    {


    }


}
