<?php

namespace Sunnysideup\VersionPruner\PruningTemplates;

use Sunnysideup\VersionPruner\PruningTemplatesTemplate;

class BasedOnTimeScale extends PruningTemplatesTemplate
{
    protected $timeScale = [
        'Minutes' => [
            'Max' => 60,
            'Interval' => 15,
        ],
        'Hours' => [
            'Max' => 24,
            'Interval' => 3,
        ],
        'Days' => [
            'Min' => 1,
            'Max' => 7,
            'Interval' => 1,
        ],
        'Weeks' => [
            'Min' => 1,
            'Max' => 4,
            'Interval' => 1,
        ],
        'Months' => [
            'Min' => 1,
            'Max' => 12,
            'Interval' => 1,
        ],
        'Years' => [
            'Min' => 1,
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
        $toKeep = $this->buildTimeScalePatternAndOnesToKeep();
        $query = $this->getBaseQuery()
            ->addWhere(
                [
                    '"RecordID" = ?' => $this->object->ID,
                    '"Version" NOT IN (' . implode(',', $toKeep) . ')',
                ] +
                $this->otherFilters
            )
        ;

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
            for ($i = $min; $i < $max; $i += $interval) {
                $untilTs = strtotime('-' . $i . ' ' . $name);
                $fromTs = strtotime('-' . ($i + $interval) . ' ' . $name);
                $where =
                    '(
                        "LastEdited" BETWEEN
                            TIMESTAMP(' . date('Y-m-d h:i:s', $fromTs) . ')
                            AND TIMESTAMP(' . date('Y-m-d h:i:s', $untilTs) . ')
                    )';
                echo $where;
                $query = $this->getBaseQuery()
                    ->addWhere($this->normaliseWhere([$where] + $this->otherFilters))
                    ->setLimit(1)
                ;

                $keep = $this->addVersionNumberToArray(
                    $keep,
                    $query->execute()
                );
            }
        }

        return $keep;
    }
}
