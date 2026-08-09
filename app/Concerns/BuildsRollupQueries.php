<?php

namespace App\Concerns;

use App\Services\RecordService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SQL-building helpers shared by services that read the `record_rollups` /
 * `record_user_buckets` family of tables: JSON payload extraction,
 * identifier quoting, time-bucket formatting, and the small bits of
 * arithmetic (average duration, time-series gap filling) that don't belong
 * to any one project.
 *
 * Every JSON path used against `payload` is a single top-level key (see
 * callers), so extraction uses the `->>` operator, which both PostgreSQL's
 * `json` type and SQLite's JSON1 extension support — no driver branching
 * needed there. The one gotcha: PostgreSQL's `->>` always returns text,
 * while SQLite's returns the value in its native type (an integer stays an
 * integer), which breaks equality/`whereIn` comparisons against bound
 * string parameters. {@see jsonText} casts to text explicitly so both
 * engines behave the same way.
 *
 * Branching remains only where the two engines genuinely disagree: casting
 * arbitrary text to a number (Postgres errors on non-numeric input, SQLite
 * doesn't, see {@see jsonNumeric}) and formatting a date into a bucket
 * string (Postgres' `to_char` tokens vs. `strftime`-style `%` tokens, see
 * {@see timeBucketSql}).
 *
 * Used by {@see RecordService}.
 */
trait BuildsRollupQueries
{
    private function isPgsql(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    private function jsonText(string $path): string
    {
        return "CAST(payload ->> '{$path}' AS TEXT)";
    }

    private function jsonNumeric(string $path): string
    {
        $text = $this->jsonText($path);

        if ($this->isPgsql()) {
            $trimmed = "NULLIF(BTRIM({$text}), '')";

            return "CASE WHEN {$trimmed} ~ '^[+-]?([0-9]+([.][0-9]+)?|[.][0-9]+)$' THEN ({$trimmed})::numeric ELSE NULL END";
        }

        return "CAST(NULLIF({$text}, '') AS DECIMAL(20,6))";
    }

    private function timeBucketSql(string $period, string $column = 'created_at'): string
    {
        if ($this->isPgsql()) {
            return match ($period) {
                '7d', '14d', '30d' => "to_char({$column}, 'MM-DD')",
                'custom' => "to_char(date_trunc('hour', {$column}), 'YYYY-MM-DD HH24:00')",
                default => "to_char({$column}, 'HH24:MI')",
            };
        }

        return match ($period) {
            '7d', '14d', '30d' => "DATE_FORMAT({$column}, '%m-%d')",
            'custom' => "DATE_FORMAT({$column}, '%Y-%m-%d %H:00')",
            default => "DATE_FORMAT({$column}, '%H:%i')",
        };
    }

    /**
     * Quote an identifier for the active driver.
     */
    private function col(string $name): string
    {
        return DB::connection()->getQueryGrammar()->wrap($name);
    }

    /**
     * The mean duration. Averages are not additive, so they are recomputed
     * from the summed numerator/denominator rather than stored directly.
     */
    protected function avgDuration(object $totals): float
    {
        $count = (int) ($totals->count_duration ?? 0);

        return $count > 0 ? ((float) $totals->sum_duration) / $count : 0.0;
    }

    /**
     * The chart key a grouped row belongs to.
     */
    private function seriesKey(string $value, bool $groupedByMinute): string
    {
        return $groupedByMinute ? Carbon::parse($value)->format('H:i') : $value;
    }

    /**
     * Fill missing time slots with zeroed data.
     */
    protected function fillTimeSeriesGaps($results, string $period, ?string $from = null, ?string $to = null): array
    {
        $data = [];
        $now = now();

        $iterations = match ($period) {
            '1h' => 60,
            '24h' => 1440,
            '7d' => 7,
            '14d' => 14,
            '30d' => 30,
            default => 60,
        };

        $unit = match ($period) {
            '7d', '14d', '30d' => 'day',
            default => 'minute',
        };

        $dateFormat = match ($period) {
            '7d', '14d', '30d' => 'm-d',
            '24h' => 'H:i',
            default => 'H:i',
        };

        for ($i = $iterations - 1; $i >= 0; $i--) {
            $time = (clone $now)->sub($unit, $i);
            $key = $time->format($dateFormat);

            if ($results->has($key)) {
                $data[] = $results->get($key);
            } else {
                $data[] = [
                    'minute' => $key,
                    'total' => 0,
                    'ok' => 0,
                    'client_error' => 0,
                    'server_error' => 0,
                    'avg_duration' => 0,
                    'hits' => 0,
                    'misses' => 0,
                    'writes' => 0,
                    'active_users' => 0,
                    'total_requests' => 0,
                ];
            }
        }

        return $data;
    }
}
