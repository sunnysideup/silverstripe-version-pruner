<?php

namespace Sunnysideup\VersionPruner\PruningTemplates;

use SilverStripe\ORM\DB;
use Sunnysideup\VersionPruner\PruningTemplatesTemplate;

class UserChanged extends PruningTemplatesTemplate
{
    protected $keepVersions = 3;

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

    public function run(?bool $verbose = false)
    {
        DB::query('SELECT * FROM SiteTree_Versions WHERE AuthorID > 0 AND RecordID = ' . $this->object->ID);
        $this->markOlderItemsWithoutAuthor();
    }

    /**
     * these can be deleted.
     */
    protected function markOlderItemsWithoutAuthor()
    {
        $filter['"AuthorID" = ?'] = 0;
        $query = $this->getBaseQuery(['AuthorID'])
            ->addWhere($this->normaliseWhere($filter))
            ->setLimit($this->normaliseLimit(), $this->normaliseOffset($this->keepVersions))
        ;
        $this->toDelete[$this->getUniqueKey()] += $this->addVersionNumberToArray(
            $this->toDelete[$this->getUniqueKey()],
            $query->execute()
        );
    }
}
