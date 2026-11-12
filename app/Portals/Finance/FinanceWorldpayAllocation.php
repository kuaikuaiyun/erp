<?php

namespace App\Portals\Finance;

class FinanceWorldpayAllocation
{
    public const HEADERS = ['年月', '团队', '月成功单数', '月失败单数', '团队成功单数', '占比', '分配失败单数', '分配失败金额（美元）'];

    private array $months = [];

    public function add(array $row): array
    {
        $month = $row['month'];
        $this->months[$month] ??= ['failed' => 0, 'teams' => []];
        if ($row['success']) {
            $team = filled($row['team']) ? $row['team'] : '未归属';
            $this->months[$month]['teams'][$team] = ($this->months[$month]['teams'][$team] ?? 0) + 1;
        } elseif ($row['failed']) {
            $this->months[$month]['failed']++;
        }
        return $row;
    }

    public function rows(): iterable
    {
        ksort($this->months);
        foreach ($this->months as $month => ['failed' => $failed, 'teams' => $teams]) {
            $success = array_sum($teams);
            if ($success === 0) {
                yield [$month, '未分配', 0, $failed, 0, '0.00%', $failed, $failed * 0.30];
                continue;
            }
            ksort($teams, SORT_NATURAL);
            $shares = $remainders = [];
            foreach ($teams as $team => $count) {
                $numerator = $failed * $count;
                $shares[$team] = intdiv($numerator, $success);
                $remainders[$team] = $numerator % $success;
            }
            arsort($remainders, SORT_NUMERIC);
            $remaining = $failed - array_sum($shares);
            foreach (array_slice(array_keys($remainders), 0, $remaining) as $team) {
                $shares[$team]++;
            }
            foreach ($teams as $team => $count) {
                yield [$month, $team, $success, $failed, $count, number_format(100 * $count / $success, 2).'%',
                    $shares[$team], $shares[$team] * 0.30];
            }
        }
    }
}
