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
            $versionCount = $this->getObjectCountPerClassName($className);
            if ($count) {
                $object = DataObject::get_one($className);
                if ($object) {
                    $array = $runner->getTemplatesDescription($object);
                    if (count($array)) {
                        DB::alteration_message('-----------------------------------');
                        DB::alteration_message($name . ' (' . $count . ' records, '.$versionCount.' version records) ' . $className);
                        DB::alteration_message('... ' . $className);
                        foreach ($array as $string) {
                            DB::alteration_message('... ... ' . $string);
                        }
                    }
                    $array = $runner->getTableSizes($object, true);
                    if(! empty($array)) {
                        DB::alteration_message('... Version Records');
                        foreach($array as $table => $size) {
                            DB::alteration_message('... ... ' . $table.': '. number_format($size));
                        }
                    }
                }
            }
        }
    }

    protected $objectCountPerClassNameCache = [];
    protected $objectCountForVersionsPerClassNameCache = [];

    protected function getObjectCountPerClassName(string $className): int
    {
        if(! isset($this->objectCountPerClassNameCache[$className])) {
            $this->objectCountPerClassNameCache[$className] = $className::get()->limit(100000)->count();
        }
        return $this->objectCountPerClassNameCache[$className];
    }

    protected function getObjectCountForVersionsPerClassName(string $className): int
    {
        if(! isset($this->objectCountForVersionsPerClassNameCache[$className])) {
            $tableName = Config::inst()->get($className, 'table_name');
            $this->objectCountForVersionsPerClassNameCache[$className] = (int) DB::query('SELECT COUNT("ID") FROM "'.$tableName.'_Versions";')->value();
        }
        return $this->objectCountForVersionsPerClassNameCache[$className];
    }
}
