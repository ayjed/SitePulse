<?php

namespace App\Support\Dashboard;

use App\Models\CheckResult;
use Illuminate\Database\Eloquent\Collection;

final class LatencyChart
{
    /**
     * @param  Collection<int, CheckResult>  $samples  Newest-first samples.
     * @return array{samples: Collection<int, CheckResult>, points: list<array{x: float, y: float}>, maximum: int, lowest: ?int, highest: ?int}
     */
    public static function from(Collection $samples): array
    {
        $samples = $samples->reverse()->values();
        $first = $samples->first()?->checked_at->getTimestamp();
        $last = $samples->last()?->checked_at->getTimestamp();
        $span = $samples->isEmpty() ? 0 : $last - $first;
        $values = $samples->pluck('latency_ms')->filter(fn (?int $value): bool => $value !== null);
        $maximum = max(20, (int) ceil(($values->max() ?? 0) / 20) * 20);

        $points = [];
        foreach ($samples as $sample) {
            if ($sample->latency_ms === null) {
                continue;
            }

            $points[] = [
                'x' => $span === 0 ? 360.0 : round(8 + 704 * ($sample->checked_at->getTimestamp() - $first) / $span, 2),
                'y' => round(152 - 144 * $sample->latency_ms / $maximum, 2),
            ];
        }

        return [
            'samples' => $samples,
            'points' => $points,
            'maximum' => $maximum,
            'lowest' => $values->min(),
            'highest' => $values->max(),
        ];
    }
}
