<?php

namespace Sunnysideup\VersionPruner\Api;

use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;

use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataList;
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
        $allClasses = ClassInfo::subclassesFor(DataObject::class);
        $runner = RunForOneObject::inst()
        foreach ($allClasses as $className) {
            $name = Injector::inst()->get($className)->i18n_singular_name();
            $count = $this->getObjectCountPerClassName($className);
            if($count) {
                $object = DataObject::get_one($className);
                DB::alteration_message($name .' ('.$count.' records) - '.$runner->getTemplateDescription($this->object);
            }
            DB::alteration_message(=)
        }
    }

    protected function getObjectCountPerClassName(string $className): int
    {
        return Versioned::get_by_stage($className, Versioned::DRAFT)
            ->sort(DB::get_conn()->random() . ' ASC')
            ->limit()
            ->count()
        ;
    }





}
