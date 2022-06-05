<?php
namespace Sunnysideup\VersionPruner;

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


abstract class PruningTemplatesTemplate
{

    use Configurable;


    protected $object = null;

    protected $baseTable = '';

    protected $toDelete = [];

    private $uniqueKey = '';

    /**
     * list of Versions
     * @var array
     */

    public function __construct($object, array $toDelete)
    {
        $this->object = $object;
        $this->toDelete[$this->getUniqueKey()] = $toDelete;
        $this->baseTable = $this->object->baseTable();
    }

    /**
     * adds / removes records to be deleted.
     */
    abstract public function run();

    public function getToDelete(string $baseTable) : array
    {
        return $this->toDelete[$this->getUniqueKey()];
    }


    public function setBaseTable() : self
    {
        $this->baseTable = $this->object->baseTable();
        return $this;
    }

    public function setToDelete(array $toDelete) : self
    {
        $this->toDelete[$this->getUniqueKey()] = $toDelete;
        return $this;
    }

    /**
     * we use this unique key to accidentally mix up records
     * @return string
     */
    protected function getUniqueKey() : string
    {
        return $this->uniqueKey = $this->object->ClassName . '_' . $this->object->ID;
    }


    protected function addVersionNumberToArray(array $array, $records, ?string $field = 'Version') : array
    {
        foreach($record as $record) {
            $array[$record[$field]] = $record[$field];
        }

        return $array;
    }

    protected function getBaseQuery(?array $additionalFieldsToSelect = []) : SQLSelect
    {
        $fields = [
            'ID',
            'Version',
            'LastEdited',
        ];
        $fields = array_merge($fields, $additionalFieldsToSelect);

        $query = new SQLSelect();
        $query->setFrom($this->baseTable . '_Versions');
        $query->setSelect($fields);
        $query->setOrderBy('ID DESC');

        return $query;
    }

}
