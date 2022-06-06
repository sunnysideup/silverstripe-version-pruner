<?php

namespace Sunnysideup\VersionPruner\Api;
use Sunnysideup\VersionPruner\PruningTemplates\BasedOnTimeScale;
use Sunnysideup\VersionPruner\PruningTemplates\DeleteFiles;
use Sunnysideup\VersionPruner\PruningTemplates\Drafts;
use Sunnysideup\VersionPruner\PruningTemplates\SiteTreeVersioningTemplate;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;

class RunForOneObject
{

    use Configurable;
    use Injectable;

    protected $object;

    protected $baseTable = '';

    protected $toDelete = [];

    protected static $tables_per_class_name = [];

    /**
     * schema is:
     * ```php
     *     ClassName => [
     *         PruningTemplateClassName1 => [
     *             "PropertyName1" => Value1
     *             "PropertyName2" => Value2
     *         ],
     *         PruningTemplateClassName2 => [
     *         ],
     *     ]
     * ```.
     *
     * @var array
     */
    private static $templates = [
        'default' => [
            BasedOnTimeScale::class => [],
        ],
        SiteTree::class => [
            Drafts::class => [],
            SiteTreeVersioningTemplate::class => [],
        ],
        File::class => [
            DeleteFiles::class => [],
            BasedOnTimeScale::class => [],
        ],
    ];

    public function __construct($object)
    {
        $this->object = $object;
    }

    /**
     * returns the total number deleted.
     */
    public function run(): int
    {
        if (false === $this->hasStages()) {
            return 0;
        }

        if (false === $this->object->isLiveVersion()) {
            return 0;
        }

        // array of version IDs to delete
        $this->toDelete[$this->getUniqueKey()] = [];

        // Base table has Versioned data
        $totalDeleted = 0;

        $templates = $this->Config()->get('templates');
        $myTemlates = $templates[$this->object->ClassName] ?? $templates['default'];
        foreach ($myTemplates as $className => $options) {
            $obj = new $className($this->object, $this->toDelete[$this->getUniqueKey()]);
            foreach ($options as $key => $value) {
                $method = 'set' . $key;
                $obj->{$method}($value);
            }
            $obj->run();
            $this->toDelete[$this->getUniqueKey()] = $obj->getToDelete();
        }
        if (! count($this->toDelete[$this->getUniqueKey()])) {
            return 0;
        }

        // Ugly (borrowed from DataObject::class), but returns all
        // database tables relating to DataObject
        $queriedTables = $this->getTablesForClassName();
        foreach ($queriedTables as $table) {
            $delSQL = '
                DELETE FROM "' . $table . '_Versions"
                WHERE
                    "Version" IN (' . implode(',', $this->toDelete[$this->getUniqueKey()]) . ')
                    AND "RecordID" = ' . (int) $this->object->ID;

            DB::query($delSQL);

            $totalDeleted += DB::affected_rows();
        }

        return $totalDeleted;
    }

    /**
     * we use this to make sure we never mix up two records.
     */
    protected function getUniqueKey(): string
    {
        return $this->object->ClassName . '_' . $this->Object->ID;
    }

    protected function hasStages(): bool
    {
        $oldMode = Versioned::get_reading_mode();
        if ('Stage.Stage' !== $oldMode) {
            Versioned::set_reading_mode('Stage.Stage');
        }
        $hasStages = (bool) $this->object->hasStages();
        if ('Stage.Stage' !== $oldMode) {
            Versioned::set_reading_mode($oldMode);
        }

        return $this->hasStages();
    }

    protected function getTablesForClassName(): array
    {
        if (empty(self::$tables_per_class_name[$this->object->ClassName])) {
            $srcQuery = DataList::create($this->object->ClassName)
                ->filter('ID', $this->object->ID)
                ->dataQuery()
                ->query()
            ;
            self::$tables_per_class_name[$this->object->ClassName] = $srcQuery->queriedTables();
        }

        return self::$tables_per_class_name[$this->object->ClassName];
    }
}
