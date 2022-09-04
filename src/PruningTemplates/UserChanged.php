<?php

namespace Sunnysideup\VersionPruner\PruningTemplates;

use Sunnysideup\VersionPruner\PruningTemplatesTemplate;

class UserChanged extends PruningTemplatesTemplate
{
    protected $keepVersions = 10;

    protected $fieldsWithChangesToKeep = [
        'AuthorID',
    ];

    public function setKeepVersions(int $keepVersions): self
    {
        $this->keepVersions = $keepVersions;

        return $this;
    }

    public function getTitle(): string
    {
        return 'Prune automated saves';
    }

    public function getDescription(): string
    {
        return 'Delete versions that are not edited by a logged-in user.';
    }

    public function run()
    {
        $this->markOlderItemsWithoutAuthor();
    }

    /**
     * these can be deleted.
     *
     * @return [type] [description]
     */
    protected function markOlderItemsWithoutAuthor()
    {

        foreach ($this->fieldsWithChangesToKeep as $field) {
            $filter['"' . $field . '" = ?'] = 0;
        }

        $query = $this->getBaseQuery($this->fieldsWithChangesToKeep)
            ->addWhere($this->normaliseWhere($filter))
            ->setLimit($this->normaliseLimit(9999), $this->normaliseOffset($this->keepVersions))
        ;

        $this->toDelete[$this->getUniqueKey()] = $this->addVersionNumberToArray(
            $this->toDelete[$this->getUniqueKey()],
            $query->execute()
        );
    }

    protected function getItemsToKeep(): array
    {
        // Get the most recent Version IDs of all published pages to ensure
        // we leave at least X versions even if a URLSegment or ParentID
        // has changed.
        $query = $this->getBaseQuery([])
            ->addWhere(
                [
                    '"RecordID" = ?' => $this->object->ID,
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
