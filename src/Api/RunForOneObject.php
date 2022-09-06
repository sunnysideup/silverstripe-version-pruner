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
     * @var bool
     */
    protected $verbose = false;

    /**
     * @var bool
     */
    protected $dryRun = false;

    /**
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
        $this->gatherTemplates();
    }

    protected function gatherTemplates()
    {
        $this->templatesAvailable = array_reverse(
            $this->Config()->get('templates'),
            true //important - to preserve keys!
        );
        // remove skips
        foreach($this->templatesAvailable as $className => $runnerClassNameWithOptions) {
            if($runnerClassNameWithOptions === 'skip') {
                $this->templatesAvailable[$className] = 'skip';
                continue;
            }
            if(is_array($runnerClassNameWithOptions)) {
                foreach($runnerClassNameWithOptions as $runnerClassName => $options) {
                    if($options === 'skip') {
                        unset($this->templatesAvailable[$className][$runnerClassName]);
                        continue;
                    }
                }
            }
        }
    }

    public static function inst()
    {
        return Injector::inst()->get(static::class);
    }

    public function setVerbose(?bool $verbose = true): self
    {
        $this->verbose = $verbose;

        return $this;
    }

    public function setDryRun(?bool $dryRun = true): self
    {
        $this->dryRun = $dryRun;

        return $this;
    }

    /**
     * returns the total number deleted.
     *
     * @param DataObject $object
     *
     */
    public function getTableSizes($object, ?bool $lastOnly = true): array
    {
        $this->object = $object;
        $array = [];
        if ($this->isValidObject()) {
            $queriedTables = $this->getTablesForClassName();
            // print_r($this->toDelete[$this->getUniqueKey()]);
            foreach ($queriedTables as $table) {
                $array[$table] = $this->getCountPerTable($table);
            }
        }
        if(count($array) && $lastOnly) {
            $lastKey = array_key_last($array);
            return [
                $lastKey => $array[$lastKey],
            ];
        }
        return $array;
    }

    /**
     * returns the total number deleted.
     *
     * @param DataObject $object
     *
     */
    public function getRootTable($object): ?string
    {
        $this->object = $object;
        $array = [];
        if ($this->isValidObject()) {
            $queriedTables = $this->getTablesForClassName();
            // print_r($this->toDelete[$this->getUniqueKey()]);
            foreach ($queriedTables as $table) {
                return $table;
            }
        }
        return null;
    }

    /**
     * returns the total number deleted.
     *
     * @param DataObject $object
     *
     * @return int number of deletions
     */
    public function deleteSuperfluousVersions($object): int
    {
        $this->object = $object;
        if (! $this->isValidObject()) {
            return 0;
        }
        // reset to reduce size ...
        $this->toDelete = [];

        $this->workoutWhatNeedsDeleting();

        // Base table has Versioned data
        $totalDeleted = 0;

        // Ugly (borrowed from DataObject::class), but returns all
        // database tables relating to DataObject
        $queriedTables = $this->getTablesForClassName();
        // print_r($this->toDelete[$this->getUniqueKey()]);
        foreach ($queriedTables as $table) {
            $overallCount = $this->getCountPerTable($table);
            if($this->verbose) {
                $selectToBeDeletedSQL = '
                    SELECT COUNT(ID) AS C FROM "' . $table . '_Versions"
                    WHERE "RecordID" = ' . (int) $this->object->ID;
                $totalRows = DB::query($selectToBeDeletedSQL)->value();
                DB::alteration_message('... ... ... Number of rows for current object in '.$table.': '.$totalRows);
            }
            if (count($this->toDelete[$this->getUniqueKey()])) {
                if (true === $this->dryRun) {
                    $selectToBeDeletedSQL = '
                        SELECT COUNT(ID) AS C FROM "' . $table . '_Versions"
                        WHERE
                            "Version" IN (' . implode(',', $this->toDelete[$this->getUniqueKey()]) . ')
                            AND "RecordID" = ' . (int) $this->object->ID;

                    $toBeDeletedCount = DB::query($selectToBeDeletedSQL)->value();
                    $totalDeleted += $toBeDeletedCount;
                    if ($this->verbose) {
                        DB::alteration_message('... ... ... running ' . $selectToBeDeletedSQL);
                        DB::alteration_message('... ... ... total rows to be deleted  ... ' . $toBeDeletedCount . ' of ' . $overallCount);
                    }
                } else {
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
        if ($this->isValidObject()) {
            $myTemplates = $this->findBestSuitedTemplates(true);
            if(is_array($myTemplates) && count($myTemplates)) {
                foreach ($myTemplates as $className => $options) {
                    if(class_exists($className)) {
                        $runner = new $className($this->object, []);
                        $array[] = $runner->getTitle() . ': ' . $runner->getDescription();
                    } else {
                        $array[] = $options;
                    }
                }
            }
        }

        return $array;
    }

    public function getCountRegister(): array
    {
        return $this->countPerTableRegister;
    }

    protected function workoutWhatNeedsDeleting()
    {
        // array of version IDs to delete
        // IMPORTANT
        if(! isset($this->toDelete[$this->getUniqueKey()])) {
            $this->toDelete[$this->getUniqueKey()] = [];
        }

        $myTemplates = $this->findBestSuitedTemplates(false);
        if(is_array($myTemplates) && !empty($myTemplates)) {
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
                // print_r($runner->getToDelete());
                $this->toDelete[$this->getUniqueKey()] += $runner->getToDelete();

                if ($this->verbose) {
                    DB::alteration_message('... ... ... Total versions to delete now ' . count($this->toDelete[$this->getUniqueKey()]));
                }
            }
        }
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
        if ($this->object->hasMethod('hasStages')) {
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

    protected function findBestSuitedTemplates(?bool $forExplanation = false)
    {
        if (empty($this->templatesPerClassName[$this->object->ClassName]) || $forExplanation) {
            foreach ($this->templatesAvailable as $className => $classesWithOptions) {
                if (is_a($this->object, $className)) {
                    // if($forExplanation && $className !== $this->object->ClassName) {
                    //     echo "$className !== {$this->object->ClassName}";
                    //     $this->templatesPerClassName[$this->object->ClassName] = ['As '.$className];
                    // }
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

    protected function isValidObject(): bool
    {
        if (false === $this->hasStages()) {
            if ($this->verbose) {
                DB::alteration_message('... ... ... Error, no stages', 'deleted');
            }

            return false;
        }

        // if (! $this->object->hasMethod('isLiveVersion')) {
        //     return false;
        // }
        //
        // if (false === $this->object->isLiveVersion()) {
        //     if ($this->verbose) {
        //         DB::alteration_message('... ... ... Error, not a live version', 'deleted');
        //     }
        //
        //     return false;
        // }

        return $this->object && $this->object->exists();
    }

    protected function getTablesForClassName(): array
    {
        if (empty($this->tablesPerClassName[$this->object->ClassName])) {
            // $classTables = []
            // $allClasses = ClassInfo::subclassesFor($this->object->ClassName, true);
            // foreach ($allClasses as $class) {
            //     if (DataObject::getSchema()->classHasTable($class)) {
            //         $classTables[] = DataObject::getSchema()->tableName($class);
            //     }
            // }
            // $this->tablesPerClassName[$this->object->ClassName] = array_unique($classTables);

            $srcQuery = DataList::create($this->object->ClassName)
                ->filter('ID', $this->object->ID)
                ->dataQuery()
                ->query()
            ;
            $this->tablesPerClassName[$this->object->ClassName] = $srcQuery->queriedTables();
        }

        return $this->tablesPerClassName[$this->object->ClassName];
    }

    protected function addCountRegister(string $tableName, int $count): void
    {
        $this->countPerTableRegister[$tableName] = $count;
    }


    protected function getCountPerTable(string $table) : int
    {
        $overallCount = $this->countPerTableRegister[$table] ?? -1;
        if($overallCount === -1) {
            $selectOverallCountSQL = '
                SELECT COUNT(ID) AS C FROM "' . $table . '_Versions"';
            $overallCount = DB::query($selectOverallCountSQL)->value();
        }

        return $overallCount;
    }

}
