<?php

namespace Sunnysideup\VersionPruner\PruningTemplates;

use SilverStripe\ORM\Queries\SQLSelect;
use Sunnysideup\VersionPruner\PruningTemplatesTemplate;

class DeleteFiles extends PruningTemplatesTemplate
{
    public function getTitle(): string
    {
        return 'File specific pruning';
    }

    public function getDescription(): string
    {
        return 'All versions are deleted for files that have been deleted.';
    }

    /**
     * Prune versions of deleted files/folders.
     */
    public function run()
    {
        if ($this->hasBeenDeleted()) {
            $query = $this->getBaseQuery()
                ->addWhere(['"RecordID" = ?' => $this->object->ID])
            ;
            //starting from "keepVersions" - going backwards in time
            $this->toDelete[$this->getUniqueKey()] = $this->addVersionNumberToArray(
                $this->toDelete[$this->getUniqueKey()],
                $query->execute()
            );
        }
    }

    protected function hasBeenDeleted(): bool
    {
        return (new SQLSelect())
            ->setFrom($this->baseTable . '_Versions')
            ->setSelect(['RecordID, WasDeleted'])
            ->addWhere($this->normaliseWhere(['"WasDeleted" = ?' => 1]))
            ->setLimit(1)
            ->count('ID') > 0 ? true : false;
        ;
    }
}
