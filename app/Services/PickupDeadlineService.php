<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class PickupDeadlineService
{
    public const BUSINESS_DAYS = 10;

    public function calculate(CarbonInterface $readyAt): CarbonImmutable
    {
        $date = CarbonImmutable::instance($readyAt);
        $counted = 0;

        while ($counted < self::BUSINESS_DAYS) {
            $date = $date->addDay();
            if (! $date->isWeekend()) {
                $counted++;
            }
        }

        return $date->endOfDay();
    }
}
