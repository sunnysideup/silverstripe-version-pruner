<?php

namespace Sunnysideup\VersionPruner\PruningTemplates;

use Sunnysideup\VersionPruner\PruningTemplatesTemplate;

class Drafts extends PruningTemplatesTemplate
{
    private $keepDraftCount = 10;

    public function getTitle(): string
    {
        return 'Prune drafts';
    }

    public function getDescription(): string
    {
        return 'Keep ' . $this->keepDraftCount . ' drafts and delete all other drafts.';
    }

    /**
     * here for legacy reasons
     */
    public function setkeepDrafts(int $keepDraftCount): self
    {
        $this->keepDraftCount = $keepDraftCount;

        return $this;
    }

    public function setkeepDraftCount(int $keepDraftCount): self
    {
        $this->keepDraftCount = $keepDraftCount;

        return $this;
    }

    public function run(?bool $verbose = false)
    {
        // remove drafts keeping `keep_drafts`
        if ($this->keepDraftCount > 0) {
            $query = $this->getBaseQuery(['WasPublished'])
                ->addWhere(
                    [
                        'RecordID = ' . $this->object->ID,
                        'WasPublished = 0',
                    ]
                )
                ->setLimit($this->normaliseLimit(), $this->normaliseOffset($this->keepDraftCount))
            ;

            $this->toDelete[$this->getUniqueKey()] += $this->addVersionNumberToArray(
                $this->toDelete[$this->getUniqueKey()],
                $query->execute()
            );
        }
    }
}
