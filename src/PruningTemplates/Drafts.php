<?php

namespace Sunnysideup\VersionPruner\PruningTemplates;

use Sunnysideup\VersionPruner\PruningTemplatesTemplate;

class Drafts extends PruningTemplatesTemplate
{
    private static $keepDrafts = 10;

    public function setKeepDrafts(int $keepDrafts): self
    {
        $this->keepDrafts = $keepDrafts;

        return $this;
    }

    protected function run()
    {
        // remove drafts keeping `keep_drafts`
        if ($keepDrafts > 0) {
            $query = $this->getBaseQuery();
            $query->addWhere(
                'RecordID = ' . $this->object->ID,
                'WasPublished = 0'
            );

            //todo: check limit!
            $query->setLimit($keepDrafts, 0);

            $this->toDelete[$this->getUniqueKey()] = $this->addVersionNumberToArray(
                $this->toDelete[$this->getUniqueKey()],
                $query->execute()
            );
        }
    }
}
