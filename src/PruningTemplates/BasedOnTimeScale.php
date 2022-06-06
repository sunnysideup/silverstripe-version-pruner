<?php

namespace Sunnysideup\VersionPruner\PruningTemplates;

use Sunnysideup\VersionPruner\PruningTemplatesTemplate;

class BasedOnTimeScale extends PruningTemplatesTemplate
{
    protected $timeScale = [
        'Minutes' => [
            'Max' => 60,
            'Interval' => 5,
        ],
        'Hours' => [
            'Max' => 24,
            'Interval' => 2,
        ],
        'Days' => [
            'Max' => 24,
            'Interval' => 7,
        ],
        'Weeks' => [
            'Min' => 4,
            'Max' => 4,
            'Interval' => 2,
        ],
        'Months' => [
            'Max' => 18,
            'Interval' => 1,
        ],
        'Years' => [
            'Max' => 7,
            'Interval' => 1,
        ],
    ];

    protected $otherFilters = [
        '"WasPublished" = ?' => 1,
    ];

    public function setOtherFilters(array $otherFilters): self
    {
        $this->otherFilters = $otherFilters;

        return $this;
    }

    public function setTimeScale(array $timeScale): self
    {
        $this->timeScale = $timeScale;

        return $this;
    }

    public function run()
    {
        $keep = $this->buildTimeScalePatternAndOnesToKeep();

        $query->addWhere(
            [
                '"RecordID" = ?' => $this->object->ID,
                '"Version" NOT IN (' . implode(',', $toKeep) . ')',
            ] +
            $this->otherFilters
        );

        $results = $query->execute();

        $this->toDelete[$this->getUniqueKey()] = $this->addVersionNumberToArray(
            $this->toDelete[$this->getUniqueKey()],
            $query->execute()
        );
    }

    protected function buildTimeScalePatternAndOnesToKeep(): array
    {
        $keep = [];
        foreach ($this->timeScale as $name => $options) {
            $min = $options['Min'] ?? 0;
            $max = $options['Max'] ?? 7;
            $interval = (int) $options['Interval'] ?? 1;
            for ($i = $min; $i < $max; $i = $i + $interval) {
                $untilTs = strtotime('-' . $i . ' ' . $name);
                $fromTs = strtotime('-' . ($i + $interval) . ' ' . $name);
                $where =
                    '(
                        "LastEdited" BETWEEN
                            TIMESTAMP(' . date('Y-m-d h:i:s', $fromTs) . ')
                            AND TIMESTAMP(' . date('Y-m-d h:i:s', $untilTs) . ')
                    )';
                $query = $this->getBaseQuery();
                $query->addWhere(
                    [
                        'RecordID = ' . $this->object->ID,
                        $where,
                    ] +
                    $this->otherFilters
                );

                //todo: check limit!
                $query->setLimit(1);

                $keep = $this->addVersionNumberToArray(
                    $keep,
                    $query->execute()
                );
            }
        }

        return $keep;
    }
}
