<?php

namespace Sunnysideup\VersionPruner\Tasks;

use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use Sunnysideup\VersionPruner\Api\RunForOneObject;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

class PruneAllVersionedRecordsReviewTemplates extends BuildTask
{
    /**
     * @var string
     */
    protected static string $commandName = 'prune-all-versioned-records-review-templates';

    /**
     * @var string
     */
    protected string $title = 'Prune all versioned records - review templates for each dataobject';

    /**
     * @var string
     */
    protected static string $description = 'Go through all dataobjects and shows the pruning schedule.';

    protected $objectCountPerClassNameCache = [];

    protected $objectCountForVersionsPerClassNameCache = [];

    /**
     * Prune all published DataObjects which are published according to config.
     */
    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $allClasses = ClassInfo::subclassesFor(DataObject::class, false);
        $runner = RunForOneObject::inst();
        Versioned::set_stage(Versioned::DRAFT);
        foreach ($allClasses as $className) {
            $name = Injector::inst()->get($className)->i18n_singular_name();
            $count = $this->getObjectCountPerClassName($className);
            if ($count !== 0) {
                $object = DataObject::get_one($className);
                if ($object) {
                    $array = $runner->getTemplatesDescription($object);
                    if (count($array) > 0) {
                        $output->writeln($name . ' (' . $count . ' records) ' . $className);
                        $output->writeln('... ' . $className);
                        foreach ($array as $string) {
                            $output->writeln('... ... ' . $string);
                        }
                    }

                    // No data for this className - commented out for clarity

                    $array = $runner->getTableSizes($object, true);
                    if (! empty($array)) {
                        $output->writeln('... Version Records');
                        foreach ($array as $table => $size) {
                            $output->writeln('... ... ' . $table . ': ' . number_format($size));
                        }
                    }
                }
            }
        }

        return Command::SUCCESS;
    }

    protected function getObjectCountPerClassName(string $className): int
    {
        if (! isset($this->objectCountPerClassNameCache[$className])) {
            $this->objectCountPerClassNameCache[$className] = $className::get()->count();
        }

        return $this->objectCountPerClassNameCache[$className];
    }

    protected function getObjectCountForVersionsPerClassName(string $className): int
    {
        if (! isset($this->objectCountForVersionsPerClassNameCache[$className])) {
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
