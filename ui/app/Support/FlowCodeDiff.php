<?php

namespace App\Support;

class FlowCodeDiff
{
    public function build(string $from, string $to): string
    {
        if ($from === $to) {
            return '';
        }

        if (function_exists('xdiff_string_diff')) {
            $diff = call_user_func('xdiff_string_diff', $from, $to, 1);

            if (is_string($diff)) {
                return $diff;
            }
        }

        $fromLines = preg_split('/\R/', $from) ?: [];
        $toLines = preg_split('/\R/', $to) ?: [];
        $fromCount = count($fromLines);
        $toCount = count($toLines);

        $dp = array_fill(0, $fromCount + 1, array_fill(0, $toCount + 1, 0));

        for ($i = $fromCount - 1; $i >= 0; $i--) {
            for ($j = $toCount - 1; $j >= 0; $j--) {
                if ($fromLines[$i] === $toLines[$j]) {
                    $dp[$i][$j] = $dp[$i + 1][$j + 1] + 1;
                } else {
                    $dp[$i][$j] = max($dp[$i + 1][$j], $dp[$i][$j + 1]);
                }
            }
        }

        $diff = [];
        $i = 0;
        $j = 0;

        while ($i < $fromCount && $j < $toCount) {
            if ($fromLines[$i] === $toLines[$j]) {
                $diff[] = ' '.$fromLines[$i];
                $i++;
                $j++;

                continue;
            }

            if ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
                $diff[] = '-'.$fromLines[$i];
                $i++;

                continue;
            }

            $diff[] = '+'.$toLines[$j];
            $j++;
        }

        while ($i < $fromCount) {
            $diff[] = '-'.$fromLines[$i];
            $i++;
        }

        while ($j < $toCount) {
            $diff[] = '+'.$toLines[$j];
            $j++;
        }

        return implode("\n", $diff);
    }
}
