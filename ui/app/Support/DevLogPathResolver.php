<?php

namespace App\Support;

use Carbon\CarbonInterface;

class DevLogPathResolver
{
    /**
     * @return array<int, string>
     */
    public function resolve(string $logsPath, CarbonInterface $now): array
    {
        $todayLogPath = $logsPath.'/laravel-'.$now->format('Y-m-d').'.log';
        $existingLogPaths = glob($logsPath.'/laravel-*.log') ?: [];

        usort(
            $existingLogPaths,
            static fn (string $left, string $right): int => filemtime($right) <=> filemtime($left),
        );

        $resolvedLogPaths = [$todayLogPath];

        foreach ($existingLogPaths as $existingLogPath) {
            if ($existingLogPath === $todayLogPath) {
                continue;
            }

            $resolvedLogPaths[] = $existingLogPath;

            break;
        }

        return $resolvedLogPaths;
    }
}
