<?php

namespace App\Support;

use App\Models\AttendanceSession;
use Carbon\CarbonImmutable;

final class AttendanceClock
{
    public const DEFAULT_TIMEZONE = 'Asia/Manila';

    public const GRACE_PERIOD_MINUTES = 15;

    public static function timezone(): string
    {
        $timezone = config('attendance.timezone');

        return is_string($timezone) && $timezone !== ''
            ? $timezone
            : self::DEFAULT_TIMEZONE;
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::today(self::timezone());
    }

    public static function classStart(AttendanceSession $session): CarbonImmutable
    {
        $session->loadMissing('classSchedule');

        return CarbonImmutable::parse(
            $session->class_date->toDateString().' '.(string) $session->classSchedule->start_time,
            self::timezone(),
        );
    }

    public static function lateAt(AttendanceSession $session): CarbonImmutable
    {
        return self::classStart($session)
            ->addMinutes(self::GRACE_PERIOD_MINUTES);
    }

    public static function classEnd(AttendanceSession $session): CarbonImmutable
    {
        $session->loadMissing('classSchedule');

        return CarbonImmutable::parse(
            $session->class_date->toDateString().' '.(string) $session->classSchedule->end_time,
            self::timezone(),
        );
    }

    public static function statusForCheckIn(
        AttendanceSession $session,
        ?CarbonImmutable $now = null,
    ): string {
        $now ??= self::now();

        return $now->greaterThan(self::classStart($session)) ? 'late' : 'present';
    }

    public static function checkInTime(?CarbonImmutable $now = null): string
    {
        return ($now ?? self::now())
            ->setTimezone(self::timezone())
            ->format('H:i:s');
    }

    public static function formatTime(?string $value): ?string
    {
        return $value === null ? null : substr($value, 0, 5);
    }
}
