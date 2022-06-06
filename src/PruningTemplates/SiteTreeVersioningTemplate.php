<?php

namespace Sunnysideup\VersionPruner\PruningTemplates;

use Sunnysideup\VersionPruner\PruningTemplatesTemplate;

class SiteTreeVersioningTemplate extends PruningTemplatesTemplate
{
    protected $keepVersions = 30;

    protected $fieldsWithChangesToKeep = [
        'URLSegment',
        'ParentID',
    ];

    public function setKeepVersions(int $keepVersions): self
    {
        $this->keepVersions = $keepVersions;

        return $this;
    }

    public function getTitle(): string
    {
        return 'SiteTree specific version pruning';
    }

    public function getDescription(): string
    {
        return 'Delete versions that are older and do not include any changes in ParentID or URLSegment.';
    }

    public function run()
    {
        $this->markOlderItemsWithTheSameKeyValues();
        $this->markSuperfluousOnesWithDifferentKeyValues();
    }

    /**
     * these can be deleted.
     *
     * @return [type] [description]
     */
    protected function markOlderItemsWithTheSameKeyValues()
    {
        $filter = [
            '"WasPublished" = ?' => 1,
        ];

        foreach ($this->fieldsWithChangesToKeep as $field) {
            $filter['"' . $field . '" = ?'] = $this->object->{$field};
        }

        $query = $this->getBaseQuery($this->fieldsWithChangesToKeep + ['WasPublished'])
            ->addWhere($this->normaliseWhere($filter))
            ->setLimit($this->normaliseLimit(), $this->normaliseOffset($this->keepVersions))
        ;

        $this->toDelete[$this->getUniqueKey()] = $this->addVersionNumberToArray(
            $this->toDelete[$this->getUniqueKey()],
            $query->execute()
        );
    }

    protected function markSuperfluousOnesWithDifferentKeyValues()
    {
        $toKeep = $this->getItemsToKeep();
        $orFilterKey = '"' . implode('" != ? OR "', $this->fieldsWithChangesToKeep) . '" != ?';
        $orFilterValuesArray = [];
        foreach ($this->fieldsWithChangesToKeep as $field) {
            $orFilterValuesArray[] = $this->object->{$field};
        }

        $results = $this->getBaseQuery($this->fieldsWithChangesToKeep + ['WasPublished'])
            ->addWhere(
                [
                    '"RecordID" = ?' => $this->object->ID,
                    '"WasPublished" = ?' => 1,
                    '"Version" NOT IN (' . implode(',', ($toKeep + [-1 => 0])) . ')',
                    $orFilterKey => $orFilterValuesArray,
                ]
            )
            ->execute()
        ;

        $changedRecords = [];

        // create a `ParentID - $URLSegment` array to keep only a single
        // version of each for URL redirection
        foreach ($results as $result) {
            $keyArray[] = [];
            foreach ($this->fieldsWithChangesToKeep as $field) {
                $keyArray[] = $result[$field];
            }

            $key = implode('_', $keyArray);

            if (! (in_array($key, $changedRecords, true))) {
                //mark the first one, but do not mark it to delete
                $changedRecords[] = $key;
            } else {
                // the first one has been done, so we can delete others...
                $this->toDelete[$this->getUniqueKey()][$result['Version']] = $result['Version'];
            }
        }
    }

    protected function getItemsToKeep(): array
    {
        // Get the most recent Version IDs of all published pages to ensure
        // we leave at least X versions even if a URLSegment or ParentID
        // has changed.
        $query = $this->getBaseQuery(['WasPublished'])
            ->addWhere(
                [
                    '"RecordID" = ?' => $this->object->ID,
                    '"WasPublished" = ?' => 1,
                ]
            )
            ->setLimit($this->keepVersions, 0)
        ;

        return $this->addVersionNumberToArray(
            [],
            $query->execute()
        );
    }
}
