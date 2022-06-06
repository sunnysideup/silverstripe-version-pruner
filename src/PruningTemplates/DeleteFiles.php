<?php

namespace Sunnysideup\VersionPruner\PruningTemplates;

use SilverStripe\ORM\Queries\SQLSelect;
use Sunnysideup\VersionPruner\PruningTemplatesTemplate;

class DeleteFiles extends PruningTemplatesTemplate
{

    /**
     * Prune versions of deleted files/folders.
     */
    public function run()
    {
        if ($this->hasBeenDeleted()) {
            $query = $this->getBaseQuery()
                ->addWhere(['"RecordID" = ?' => $this->object->ID,]);
            //starting from "keepVersions" - going backwards in time
            $this->toDelete[$this->getUniqueKey()] = $this->addVersionNumberToArray(
                $this->toDelete[$this->getUniqueKey()],
                $query->execute()
            );
        }
    }

    protected function hasBeenDeleted(): bool
    {
        $query = (new SQLSelect())
            ->setSelect(['RecordID, WasDeleted'])
            ->addWhere($this->normaliseWhere(['"WasDeleted" = ?' => 1,]))
            ->setLimit(1);

        $hasBeenDeleted = false;

        $results = $query->execute();

        foreach ($results as $result) {
            $hasBeenDeleted = true;
        }

        return $hasBeenDeleted;
    }

}
