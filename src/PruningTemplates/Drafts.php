<?php

namespace Sunnysideup\VersionPruner\PruningTemplates;

use Sunnysideup\VersionPruner\PruningTemplatesTemplate;

class Drafts extends PruningTemplatesTemplate
{
    private static $keepDrafts = 10;

    public function getTitle() : string
    {
        return 'Prune drafts';
    }

    public function getDescription() : string
    {
        return 'Keep '.$this->keepDrafts.' drafts and delete all other drafts.';
    }

    public function setKeepDrafts(int $keepDrafts): self
    {
        $this->keepDrafts = $keepDrafts;

        return $this;
    }

    public function run()
    {
        // remove drafts keeping `keep_drafts`
        if ($this->keepDrafts > 0) {
            $query = $this->getBaseQuery(['WasPublished'])
                ->addWhere(
                    [
                        'RecordID = ' . $this->object->ID,
                        'WasPublished = 0',
                    ]
                )
                ->setLimit($this->normaliseLimit(), $this->normaliseOffset($this->keepDrafts))
            ;

            $this->toDelete[$this->getUniqueKey()] = $this->addVersionNumberToArray(
                $this->toDelete[$this->getUniqueKey()],
                $query->execute()
            );
        }
    }
}
