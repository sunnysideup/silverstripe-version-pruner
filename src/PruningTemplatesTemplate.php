<?php

namespace Sunnysideup\VersionPruner;

use SilverStripe\ORM\Queries\SQLSelect;

abstract class PruningTemplatesTemplate
{
    private const int DEFAULT_ALWAYS_KEEP = 12;

    private const int DEFAULT_MAX_DELETE_IN_ONE_GO = 1000;

    /**
     * @var string[]
     */
    private const array BASE_FIELDS = [
        'ID',
        'Version',
        'LastEdited',
    ];

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
     * @param mixed $object
     */
    public function __construct(/**
     * Versioned DataObject.
     */
    protected $object, array $toDelete)
    {
        $this->toDelete[$this->getUniqueKey()] = $toDelete;
        $this->baseTable = $this->object->baseTable();
    }

    /**
     * adds / removes records to be deleted.
     */
    abstract public function run(?bool $verbose = false);

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
        return $this->object->ClassName . '_' . $this->object->ID;
    }

    /**
     * we keep adding to array ...
     *
     * @param mixed $records
     */
    protected function addVersionNumberToArray(array $array, $records, ?string $field = 'Version'): array
    {
        $myArray = [];
        foreach ($records as $record) {
            $myArray[$record[$field]] = $record[$field];
        }

        return $array + $myArray;
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

    protected function normaliseOffset(?int $int = self::DEFAULT_ALWAYS_KEEP): int
    {
        if ($int < self::DEFAULT_ALWAYS_KEEP) {
            $int = self::DEFAULT_ALWAYS_KEEP;
        }

        return $int;
    }
}
