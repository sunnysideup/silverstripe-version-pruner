<?php

namespace Sunnysideup\VersionPruner\Api;

use SilverStripe\Assets\File;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use Sunnysideup\VersionPruner\PruningTemplates\BasedOnTimeScale;
use Sunnysideup\VersionPruner\PruningTemplates\DeleteFiles;
use Sunnysideup\VersionPruner\PruningTemplates\Drafts;
use Sunnysideup\VersionPruner\PruningTemplates\SiteTreeVersioningTemplate;

class RunForOneObject
{
    use Configurable;
    use Injectable;

    /**
     * Versioned DataObject.
     *
     * @var DataObject
     */
    protected $object;

    /**
     * array of Version numbers to delete.
     *
     * @var string
     */
    protected $toDelete = [];

    /**
     * list of tables to delete per class name.
     *
     * @var array
     */
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

    protected $verbose = false;

    /**
     * returns the total number deleted.
     * @param DataObject $object
     * @param bool      $verbose
     * @return int
     */
    public function deleteSuperfluousVersions($object, ?bool $verbose = false): int
    {
        $this->object = $object;
        $this->verbose = $verbose;
        // if (false === $this->hasStages()) {
        //     if($this->verbose) {
        //         DB::alteration_message('... ... ... Error, no stages', 'deleted');
        //     }
        //     return 0;
        // }
        //
        // echo 'B';
        // if (false === $this->object->isLiveVersion()) {
        //     if($this->verbose) {
        //         DB::alteration_message('... ... ... Error, not a live version', 'deleted');
        //     }
        //     return 0;
        // }

        // array of version IDs to delete
        // IMPORTANT
        $this->toDelete[$this->getUniqueKey()] = [];

        // Base table has Versioned data
        $totalDeleted = 0;

        $templates = $this->Config()->get('templates');
        $myTemplates = $templates[$this->object->ClassName] ?? $templates['default'];
        foreach ($myTemplates as $className => $options) {
            $runner = new $className($this->object, $this->toDelete[$this->getUniqueKey()]);
            if($this->verbose) {
                DB::alteration_message('... ... ... Running '.$runner->getTitle().': '.$runner->getDescription());
            }
            foreach ($options as $key => $value) {
                $method = 'set' . $key;
                $runner->{$method}($value);
            }

            $runner->run();
            $this->toDelete[$this->getUniqueKey()] = $runner->getToDelete();

            if($this->verbose) {
                DB::alteration_message('... ... ... total versions to delete now '.count($this->toDelete[$this->getUniqueKey()]));
            }
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
            if($this->verbose) {
                DB::alteration_message('... ... ... running '.$delSQL);
            }
            $totalDeleted += DB::affected_rows();
            if($this->verbose) {
                DB::alteration_message('... ... ... total deleted now ... '.$totalDeleted);
            }
        }

        return $totalDeleted;
    }

    /**
     * we use this to make sure we never mix up two records.
     */
    protected function getUniqueKey(): string
    {
        return $this->object->ClassName . '_' . $this->object->ID;
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

        return $hasStages;
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
