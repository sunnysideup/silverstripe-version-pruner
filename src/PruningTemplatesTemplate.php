<?php

namespace Sunnysideup\VersionPruner;

use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\Queries\SQLSelect;

abstract class PruningTemplatesTemplate
{
    /**
     * @var int
     */
    private const DEFAULT_ALWAYS_KEEP = 3;

    /**
     * @var int
     */
    private const DEFAULT_MAX_DELETE_IN_ONE_GO = 100;

    /**
     * @var string[]
     */
    private const BASE_FIELDS = [
        'ID',
        'Version',
        'LastEdited',
    ];

    /**
     * Versioned DataObject.
     *
     * @var DataObject
     */
    protected $object;

    /**
     * the table that contains the fields like Version and ClassName
     * does not include the _Version bit.
     *
     * @var string
     */
    protected $baseTable = '';

    /**
     * array of items to delete.
     *
     * @var array
     */
    protected $toDelete = [];

    /**
     * unique key to avoid mixing up records.
     *
     * @var string
     */
    private $uniqueKey = '';

    /**
     * list of Versions.
     *
     * @var array
     *
     * @param mixed $object
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

    abstract public function getTitle(): string;

    abstract public function getDescription(): string;

    public function getToDelete(): array
    {
        return $this->toDelete[$this->getUniqueKey()];
    }

    public function setBaseTable(): self
    {
        $this->baseTable = $this->object->baseTable();

        return $this;
    }

    public function setToDelete(array $toDelete): self
    {
        $this->toDelete[$this->getUniqueKey()] = $toDelete;

        return $this;
    }

    /**
     * we use this unique key to accidentally mix up records.
     */
    protected function getUniqueKey(): string
    {
        return $this->uniqueKey = $this->object->ClassName . '_' . $this->object->ID;
    }

    protected function addVersionNumberToArray(array $array, $records, ?string $field = 'Version'): array
    {
        foreach ($records as $record) {
            $array[$record[$field]] = $record[$field];
        }

        return $array;
    }

    protected function getBaseQuery(?array $additionalFieldsToSelect = []): SQLSelect
    {
        return (new SQLSelect())
            ->setFrom($this->baseTable . '_Versions') // important, of course!
            ->setSelect(array_merge(self::BASE_FIELDS, $additionalFieldsToSelect)) // not sure if we need this.
            ->setOrderBy('"ID" DESC') // important - we always work backwards
        ;
    }

    protected function normaliseWhere(array $array): array
    {
        return $array + [
            '"RecordID" = ?' => $this->object->ID,
        ];
    }

    protected function normaliseLimit(?int $int = self::DEFAULT_MAX_DELETE_IN_ONE_GO): int
    {
        if ($int > self::DEFAULT_MAX_DELETE_IN_ONE_GO) {
            $int = self::DEFAULT_MAX_DELETE_IN_ONE_GO;
        }

        return $int;
    }

    protected function normaliseOffset(?int $int = 99999): int
    {
        if ($int < self::DEFAULT_ALWAYS_KEEP) {
            $int = self::DEFAULT_ALWAYS_KEEP;
        }

        return $int;
    }
}
