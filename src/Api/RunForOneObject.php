<?php

namespace Sunnysideup\VersionPruner\Api;

use SilverStripe\Assets\File;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
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
     * reversed list of templates (most specific first).
     *
     * @var array
     */
    protected $templatesAvailable = [];

    /**
     * list of tables to delete per class name.
     *
     * @var array
     */
    protected $tablesPerClassName = [];

    /**
     * list of templates per class name.
     *
     * @var array
     */
    protected $templatesPerClassName = [];

    /**
     *
     * @var bool
     */
    protected $verbose = false;

    /**
     *
     * @var bool
     */
    protected $dryRun = false;
    /**
     *
     * @var array
     */
    protected $countPerTableRegister = [];

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
     * N.B. least specific first!
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

    public function __construct()
    {
        $this->templatesAvailable = array_reverse(
            $this->Config()->get('templates'),
            true //important - to preserve keys!
        );
    }

    public static function inst()
    {
        return Injector::inst()->get(static::class);
    }

    public function setVerbose(?bool $verbose = true) : self
    {
        $this->verbose = $verbose;
        return $this;
    }

    public function setDryRun(?bool $dryRun = true) : self
    {
        $this->dryRun = $dryRun;
        return $this;
    }

    /**
     * returns the total number deleted.
     *
     * @param DataObject $object
     * @return int number of deletions
     */
    public function deleteSuperfluousVersions($object): int
    {
        $this->object = $object;
        if(! $this->isValidObject()) {
            return 0;
        }
        // array of version IDs to delete
        // IMPORTANT
        $this->toDelete[$this->getUniqueKey()] = [];

        // Base table has Versioned data
        $totalDeleted = 0;

        $myTemplates = $this->findBestSuitedTemplates();
        foreach ($myTemplates as $className => $options) {
            $runner = new $className($this->object, $this->toDelete[$this->getUniqueKey()]);
            if ($this->verbose) {
                DB::alteration_message('... ... ... Running ' . $runner->getTitle() . ': ' . $runner->getDescription());
            }

            foreach ($options as $key => $value) {
                $method = 'set' . $key;
                $runner->{$method}($value);
            }

            $runner->run();
            $this->toDelete[$this->getUniqueKey()] = $runner->getToDelete();

            if ($this->verbose) {
                DB::alteration_message('... ... ... total versions to delete now ' . count($this->toDelete[$this->getUniqueKey()]));
            }
        }

        if (! count($this->toDelete[$this->getUniqueKey()])) {
            return 0;
        }

        // Ugly (borrowed from DataObject::class), but returns all
        // database tables relating to DataObject
        $queriedTables = $this->getTablesForClassName();
        foreach ($queriedTables as $table) {
            $selectOverallCountSQL = '
                SELECT COUNT(ID) AS C FROM "' . $table . '_Versions"';
            $overallCount = DB::query($selectOverallCountSQL)->value();
            $selectToBeDeletedSQL = '
                SELECT COUNT(ID) AS C FROM "' . $table . '_Versions"
                WHERE
                    "Version" IN (' . implode(',', $this->toDelete[$this->getUniqueKey()]) . ')
                    AND "RecordID" = ' . (int) $this->object->ID;

            $toBeDeletedCount = DB::query($selectToBeDeletedSQL)->value();
            if ($this->verbose) {
                DB::alteration_message('... ... ... running ' . $select);
                DB::alteration_message('... ... ... total rows to be deleted  ... ' . $toBeDeletedCount. ' of '.$overallCount);
            }
            if($this->dryRun === false) {
                $delSQL = '
                    DELETE FROM "' . $table . '_Versions"
                    WHERE
                        "Version" IN (' . implode(',', $this->toDelete[$this->getUniqueKey()]) . ')
                        AND "RecordID" = ' . (int) $this->object->ID;

                DB::query($delSQL);
                $count = DB::affected_rows();
                $totalDeleted += $count;
                $overallCount -= $count;
                if ($this->verbose) {
                    DB::alteration_message('... ... ... running ' . $delSQL);
                    DB::alteration_message('... ... ... total rows deleted ... ' . $totalDeleted);
                }
            }
            $this->addCountRegister($table, $overallCount);
        }

        return $totalDeleted;
    }

    /**
     * returns the total number deleted.
     *
     * @param DataObject $object
     * @param bool       $verbose
     */
    public function getTemplatesDescription($object): array
    {
        $array = [];
        $this->object = $object;
        if($this->isValidObject()) {
            $myTemplates = $this->findBestSuitedTemplates();
            foreach ($myTemplates as $className => $options) {
                $runner = new $className($this->object, []);
                $array[] =  $runner->getTitle() . ': ' . $runner->getDescription();
            }
        }

        return $array;
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
        $hasStages = false;
        if($this->object->hasMethod('hasStages')) {
            $oldMode = Versioned::get_reading_mode();
            if ('Stage.Stage' !== $oldMode) {
                Versioned::set_reading_mode('Stage.Stage');
            }

            $hasStages = (bool) $this->object->hasStages();
            if ('Stage.Stage' !== $oldMode) {
                Versioned::set_reading_mode($oldMode);
            }

        }
        return $hasStages;
    }

    protected function findBestSuitedTemplates()
    {
        if (empty($this->templatesPerClassName[$this->object->ClassName])) {
            foreach ($this->templatesAvailable as $className => $classesWithOptions) {
                if (is_a($this->object, $className)) {
                    $this->templatesPerClassName[$this->object->ClassName] = $classesWithOptions;

                    break;
                }
            }

            if (! isset($this->templatesPerClassName[$this->object->ClassName])) {
                $this->templatesPerClassName[$this->object->ClassName] = $templates['default'] ?? $classesWithOptions;
            }
        }

        return $this->templatesPerClassName[$this->object->ClassName];
    }

    protected function isValidObject() : bool
    {
        if (false === $this->hasStages()) {
            if ($this->verbose) {
                DB::alteration_message('... ... ... Error, no stages', 'deleted');
            }

            return false;
        }

        if(! $this->object->hasMethod('isLiveVersion')) {
            return false;
        }
        if (false === $this->object->isLiveVersion()) {
            if ($this->verbose) {
                DB::alteration_message('... ... ... Error, not a live version', 'deleted');
            }

            return false;
        }
        return $this->object && $this->object->exists();

    }

    protected function getTablesForClassName(): array
    {
        if (empty($this->tablesPerClassName[$this->object->ClassName])) {
            $srcQuery = DataList::create($this->object->ClassName)
                ->filter('ID', $this->object->ID)
                ->dataQuery()
                ->query()
            ;
            $this->tablesPerClassName[$this->object->ClassName] = $srcQuery->queriedTables();
        }

        return $this->tablesPerClassName[$this->object->ClassName];
    }

    public function getCountRegister() : array
    {
        return $this->countPerTableRegister;
    }

    protected function addCountRegister(string $tableName, int $count) : void
    {
        $this->countPerTableRegister[$tableName] = $count;
    }

}
