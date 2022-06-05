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

    private static $templates = [
        'default' => [
            DeleteOlderVersions::class,
        ],
        SiteTree::class => [
            Drafts::class => [],
            SiteTreeVersioningTemplate::class => [],
        ]
    ];

    protected $object = null;

    protected $baseTable = '';

    protected $toDelete = [];

    public function __construct($object)
    {
        $this->object = $object;
    }

    /**
     * returns the total number deleted
     *
     * @return int
     */
    public function doVersionCleanup() : int
    {

        if ($this->hasStages() === false) {
            return 0;
        }

        if ($this->object->isLiveVersion() === false) {
            return 0;
        }

        $this->setStage();

        // array of version IDs to delete
        $this->toDelete[$this->getUniqueKey()] = [];

        // Base table has Versioned data
        $totalDeleted = 0;

        $templates = $this->Config()->get('templates');
        $myTemlates = $templates[$this->object->ClassName] ?? $templates['default'];
        foreach($myTemplates as $className => $options) {
            $obj = new $className($this->object, $this->toDelete[$this->getUniqueKey()]);
            foreach($options as $key => $value) {
                $method = 'set'.$key;
                $obj->$method($value);
            }
            $obj->run();
            $this->toDelete[$this->getUniqueKey()] = $obj->getToDelete();
        }
        if (!count($this->toDelete[$this->getUniqueKey()])) {
            return;
        }

        // Ugly (borrowed from DataObject::class), but returns all
        // database tables relating to DataObject
        $queriedTables = $this->getTablesForClassName();
        foreach ($queriedTables as $table) {
            $delSQL = '
                DELETE FROM "'.$table.'_Versions"
                WHERE
                    "Version" IN ('.implode(',', $this->toDelete[$this->getUniqueKey()]). ')
                    AND "RecordID" = '.(int) $this->object->ID;

            DB::query($delSQL);

            $totalDeleted += DB::affected_rows();
        }

        return $totalDeleted;
    }

    /**
     * we use this to make sure we never mix up two records
     * @return string
     */
    protected function getUniqueKey() : string
    {
        return $this->object->ClassName . '_' . $this->Object->ID;
    }

    protected function hasStages() : bool
    {
        $oldMode = Versioned::get_reading_mode();
        if ($oldMode != 'Stage.Stage') {
            Versioned::set_reading_mode('Stage.Stage');
        }
        $hasStages = (bool) $this->object->hasStages();
        if ($oldMode != 'Stage.Stage') {
            Versioned::set_reading_mode($oldMode);
        }

        return $this->hasStages();
    }

    protected static $tables_per_class_name = [];

    protected function getTablesForClassName() : array
    {
        if(empty(self::$tables_per_class_name[$this->object->ClassName])) {
            $srcQuery = DataList::create($this->object->ClassName)
                ->filter('ID', $this->object->ID)
                ->dataQuery()
                ->query();
            self::$tables_per_class_name[$this->object->ClassName] = $srcQuery->queriedTables();
        }
        return self::$tables_per_class_name[$this->object->ClassName];
    }

}
