<?php
namespace Sunnysideup\VersionPruner\ExceptionPatterns;


class SiteTreeExceptions
{

    public function defition()
    {
        // Get the most recent Version IDs of all published pages to ensure
        // we leave at least X versions even if a URLSegment or ParentID
        // has changed.
        $query = new SQLSelect();
        $query->setSelect(
            ['Version', 'LastEdited']
        );
        $query->setFrom($this->baseTable . '_Versions');
        $query->addWhere(
            [
                '"RecordID" = ?'     => $this->object->ID,
                '"WasPublished" = ?' => 1,
            ]
        );
        $query->setOrderBy('ID DESC');

        //todo: check limit
        $query->setLimit($keepVersions, 0);

        $results = $query->execute();

        $to_keep = [];
        foreach ($results as $result) {
            array_push($to_keep, $result['Version']);
        }

        // only keep a single historical record of moved/renamed
        // unless they within the `keep_versions` range
        $query = new SQLSelect();
        $query->setSelect(
            ['Version', 'LastEdited', 'URLSegment', 'ParentID']
        );
        $query->setFrom($this->baseTable . '_Versions');
        $query->addWhere(
            [
                '"RecordID" = ?'                       => $this->object->ID,
                '"WasPublished" = ?'                   => 1,
                '"Version" NOT IN (' . implode(',', $to_keep) . ')',
                '"URLSegment" != ? OR "ParentID" != ?' => [
                    $this->object->URLSegment,
                    $this->object->ParentID,
                ],
            ]
        );
        $query->setOrderBy('ID DESC');

        $results = $query->execute();

        $moved_pages = [];

        // create a `ParentID - $URLSegment` array to keep only a single
        // version of each for URL redirection
        foreach ($results as $result) {
            $key = $result['ParentID'] . ' - ' . $result['URLSegment'];

            if (in_array($key, $moved_pages)) {
                array_push($this->toDelete[$this->getUniqueKey()], $result['Version']);
            } else {
                array_push($moved_pages, $key);
            }
        }
        }
    }

}
