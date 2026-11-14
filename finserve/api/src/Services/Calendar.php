<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;

/** Business days: excludes the configured weekly off-days (ISO 1=Mon … 7=Sun) and declared holidays. */
final class Calendar
{
    /** @var array<string, true>|null */
    private static ?array $holidays = null;

    public static function isBusinessDay(string $date): bool
    {
        $weekday = (new DateTimeImmutable($date))->format('N');
        $offDays = array_filter(explode(',', Settings::get('weekly_off_days')));
        if (in_array($weekday, $offDays, true)) {
            return false;
        }
        self::$holidays ??= array_fill_keys(array_column(Database::all('SELECT holiday_date FROM holidays'), 'holiday_date'), true);
        return !isset(self::$holidays[$date]);
    }

    public static function nextBusinessDay(string $date): string
    {
        $d = new DateTimeImmutable($date);
        for ($i = 0; $i < 60 && !self::isBusinessDay($d->format('Y-m-d')); $i++) {
            $d = $d->modify('+1 day');
        }
        return $d->format('Y-m-d');
    }

    /**
     * Due dates for $count installments after $start. Daily schedules step over non-business days
     * (so dues never pile onto one day); other frequencies keep their date and roll forward if it is a holiday.
     *
     * @return list<string>
     */
    public static function dueDates(string $start, string $frequency, int $count, bool $adjust): array
    {
        $dates = [];
        if ($frequency === 'daily' && $adjust) {
            $d = new DateTimeImmutable($start);
            while (count($dates) < $count) {
                $d = $d->modify('+1 day');
                if (self::isBusinessDay($d->format('Y-m-d'))) {
                    $dates[] = $d->format('Y-m-d');
                }
            }
            return $dates;
        }
        for ($n = 1; $n <= $count; $n++) {
            $date = LoanService::addPeriods($start, $frequency, $n);
            $dates[] = $adjust ? self::nextBusinessDay($date) : $date;
        }
        return $dates;
    }

    public static function reset(): void
    {
        self::$holidays = null;
    }
}
