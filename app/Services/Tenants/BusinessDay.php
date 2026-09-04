<?php

namespace App\Services\Tenants;

use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What "today" means to a shop.
 *
 * Timestamps are stored in UTC (config('app.timezone')), but a shop's day runs
 * on its own clock — and tenants.timezone exists precisely because Yangon
 * (UTC+06:30) and Bangkok (UTC+07:00) are a real half-hour apart, not a
 * rounding difference. Nothing read that column: every date filter compared
 * against UTC, so a Yangon shop's sales between midnight and 06:30 landed in
 * yesterday's dashboard, yesterday's profit report and yesterday's order
 * filter alike. Late-evening trade is ordinary here, so that is a meaningful
 * slice of the day's takings, not an edge case.
 *
 * One class rather than the conversion inlined at each call site, for the same
 * reason Order::GOODS_REVENUE_SQL is a shared constant: the dashboard card and
 * the range report must never disagree about which sales belong to a day.
 *
 * Ranges are HALF-OPEN — [start, end) — so the last instant of a day can't be
 * both counted and missed depending on sub-second precision. Same convention
 * as ProductVariant::discountActive()'s promotion window.
 */
final class BusinessDay
{
    /**
     * Falls back to the app timezone when no tenant is bound — a console
     * command or a webhook has no shop whose clock to borrow. Callers that
     * genuinely span tenants (platform reporting) must not use this at all.
     */
    public static function timezoneFor(?Tenant $tenant = null): string
    {
        $tenant ??= app()->bound('tenant') ? app('tenant') : null;

        return $tenant?->timezone ?: (string) config('app.timezone');
    }

    /**
     * The shop's current calendar date, as a Y-m-d string.
     */
    public static function today(?Tenant $tenant = null): string
    {
        return Carbon::now(self::timezoneFor($tenant))->toDateString();
    }

    /**
     * Turn a pair of the shop's LOCAL calendar dates into the UTC instants that
     * bracket them.
     *
     * A null end means "through the end of the start day", which is what a
     * single-day filter means. A null start means "from the beginning of time",
     * so an open-ended filter still works.
     *
     * The end is the start of the day AFTER `$to`: "Aug 1 to Aug 5" has to mean
     * both days in full, which is the requirement the old whereDate() calls
     * were satisfying — this keeps it while comparing a bare timestamp, so an
     * index on created_at is actually usable. DATE(created_at) wrapped the
     * column in a function and made every such filter a full scan.
     *
     * @return array{0: ?Carbon, 1: ?Carbon} both in UTC, either may be null
     */
    public static function range(?string $from, ?string $to, ?Tenant $tenant = null): array
    {
        $tz = self::timezoneFor($tenant);

        $start = $from !== null && $from !== ''
            ? Carbon::parse($from, $tz)->startOfDay()->utc()
            : null;

        $end = $to !== null && $to !== ''
            ? Carbon::parse($to, $tz)->startOfDay()->addDay()->utc()
            : null;

        return [$start, $end];
    }

    /**
     * The UTC bracket around the shop's current day.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function todayRange(?Tenant $tenant = null): array
    {
        $today = self::today($tenant);

        /** @var array{0: Carbon, 1: Carbon} */
        return self::range($today, $today, $tenant);
    }

    /**
     * SQL that renders a UTC timestamp column as the shop's LOCAL calendar
     * date, for GROUP BY in the daily breakdown.
     *
     * A fixed offset rather than a named zone: MySQL's CONVERT_TZ needs the
     * timezone tables loaded (frequently they aren't on a shared host) and
     * SQLite has no equivalent at all, so a portable expression has to do the
     * arithmetic itself. Safe for the zones this app actually serves — neither
     * Yangon nor Bangkok observes DST, so their offsets are constant. A zone
     * that DID observe it would mis-bucket sales for part of the year, so
     * supporting one means revisiting this rather than adding it to
     * SupportedCurrency and hoping.
     *
     * The offset is computed here and interpolated as an integer, never taken
     * from request input.
     */
    public static function localDateSql(string $column, ?Tenant $tenant = null): string
    {
        $offset = (int) Carbon::now(self::timezoneFor($tenant))->getOffset() / 60;

        if ($offset === 0) {
            return "DATE({$column})";
        }

        return match (DB::connection()->getDriverName()) {
            'sqlite' => sprintf("DATE(%s, '%+d minutes')", $column, $offset),
            'pgsql' => sprintf("DATE(%s + INTERVAL '%d minutes')", $column, $offset),
            default => sprintf('DATE(%s + INTERVAL %d MINUTE)', $column, $offset),
        };
    }
}
