<?php

namespace Sunnysideup\VersionPruner\PruningTemplates;

use SilverStripe\ORM\Queries\SQLSelect;
use Sunnysideup\VersionPruner\PruningTemplatesTemplate;

class DeleteFiles extends PruningTemplatesTemplate
{
    protected function hasBeenDeleted(): bool
    {
        $query = new SQLSelect();
        $query->setSelect(['RecordID']);
        $query->addWhere(['"WasDeleted" = ?' => 1, 'RecordID = ? ' => $this->object->ID]);
        $query->setLimit(1);

        $hasBeenDeleted = false;

        $results = $query->execute();

        foreach ($results as $result) {
            $hasBeenDeleted = true;
        }

        return $hasBeenDeleted;
    }

    /**
     * Prune versions of deleted files/folders.
     *
     * @return HTTPResponse
     */
    private function run()
    {
        if ($this->hasBeenDeleted()) {
            $query = $this->getBaseQuery();
            $query->addWhere(['"RecordID" = ?' => $this->object->ID]);
            //starting from "keepVersions" - going backwards in time
            $this->toDelete[$this->getUniqueKey()] = $this->addVersionNumberToArray(
                $this->toDelete[$this->getUniqueKey()],
                $query->execute()
            );
        }
    }
}
