<?php

namespace Sunnysideup\VersionPruner\Api;

use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;

class PruneAllVersionedRecordsReviewTemplates extends BuildTask
{
    /**
     * @var string
     */
    protected $title = 'Prune all versioned records - review templates for each dataobject';

    protected $description = 'Go through all dataobjects and shows the pruning schedule.';

    protected $objectCountPerClassNameCache = [];

    protected $objectCountForVersionsPerClassNameCache = [];

    /**
     * @var string
     */
    private static $segment = 'prune-all-versioned-records-review-templates';

    /**
     * Prune all published DataObjects which are published according to config.
     *
     * @param mixed $request
     */
    public function run($request)
    {
        $allClasses = ClassInfo::subclassesFor(DataObject::class, false);
        $runner = RunForOneObject::inst();
        Versioned::set_stage(Versioned::DRAFT);
        foreach ($allClasses as $className) {
            $name = Injector::inst()->get($className)->i18n_singular_name();
            $count = $this->getObjectCountPerClassName($className);
            if ($count) {
                $object = DataObject::get_one($className);
                if ($object) {
                    $array = $runner->getTemplatesDescription($object);
                    if (count($array)) {
                        DB::alteration_message($name . ' (' . $count . ' records) ' . $className);
                        DB::alteration_message('... ' . $className);
                        foreach ($array as $string) {
                            DB::alteration_message('... ... ' . $string);
                        }
                    }
                    // DB::alteration_message('No data for: '.$className);

                    $array = $runner->getTableSizes($object, true);
                    if (!empty($array)) {
                        DB::alteration_message('... Version Records');
                        foreach ($array as $table => $size) {
                            DB::alteration_message('... ... ' . $table . ': ' . number_format($size));
                        }
                    }
                }
            }
        }
    }

    protected function getObjectCountPerClassName(string $className): int
    {
        if (!isset($this->objectCountPerClassNameCache[$className])) {
            $this->objectCountPerClassNameCache[$className] = $className::get()->count();
        }

        return $this->objectCountPerClassNameCache[$className];
    }

    protected function getObjectCountForVersionsPerClassName(string $className): int
    {
        if (!isset($this->objectCountForVersionsPerClassNameCache[$className])) {
            $tableName = Config::inst()->get($className, 'table_name');
            $this->objectCountForVersionsPerClassNameCache[$className] = (int) DB::query('SELECT COUNT("ID") FROM "' . $tableName . '_Versions";')->value();
        }

        return $this->objectCountForVersionsPerClassNameCache[$className];
    }

    /**
     * Get all versioned database classes.
     */
    protected function getAllVersionedDataClassesBase(): array
    {
        $allClasses = ClassInfo::subclassesFor(DataObject::class);
        $versionedClasses = [];
        foreach ($allClasses as $className) {
            if (DataObject::has_extension($className, Versioned::class)) {
                $ancestors = ClassInfo::ancestry($className);
                foreach ($ancestors as $classNameInner) {
                    if (DataObject::has_extension($classNameInner, Versioned::class)) {
                        $versionedClasses[$classNameInner] = $classNameInner;

                        continue 2;
                    }
                }

                $versionedClasses[$className] = $className;
            }
        }

        return $versionedClasses;
    }
}
