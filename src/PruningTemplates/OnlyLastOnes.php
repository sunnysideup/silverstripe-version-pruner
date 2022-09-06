<?php

namespace Sunnysideup\VersionPruner\PruningTemplates;

use Sunnysideup\VersionPruner\PruningTemplatesTemplate;

class OnlyLastOnes extends PruningTemplatesTemplate
{
    private $keepCount = 3;

    public function getTitle(): string
    {
        return 'Prune all the last few';
    }

    public function getDescription(): string
    {
        return 'Keep ' . $this->keepCount . ' records and delete all other.';
    }

    public function setkeepCount(int $keepCount): self
    {
        $this->keepCount = $keepCount;

        return $this;
    }

    public function run(?bool $verbose = false)
    {
        // remove drafts keeping `keep_drafts`
        if ($this->keepCount > 0) {
            $query = $this->getBaseQuery(['WasPublished'])
                ->addWhere(
                    [
                        'RecordID = ' . $this->object->ID,
                    ]
                )
                ->setLimit($this->normaliseLimit(), $this->normaliseOffset($this->keepCount))
            ;

            $this->toDelete[$this->getUniqueKey()] += $this->addVersionNumberToArray(
                $this->toDelete[$this->getUniqueKey()],
                $query->execute()
            );
        }
    }
}
