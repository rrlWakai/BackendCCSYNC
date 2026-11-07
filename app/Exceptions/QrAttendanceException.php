<?php

namespace App\Exceptions;

use RuntimeException;

final class QrAttendanceException extends RuntimeException
{
    private function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    public static function invalidToken(): self
    {
        return new self(
            'token_invalid',
            'This QR code is not valid. Ask your Professor to refresh the QR code.',
            422,
        );
    }

    public static function expiredToken(): self
    {
        return new self(
            'token_expired',
            'This QR code has expired. Ask your Professor to refresh the QR code.',
            410,
        );
    }

    public static function sessionClosed(): self
    {
        return new self(
            'session_closed',
            'QR check-in is closed for this attendance session.',
            409,
        );
    }

    public static function sessionCancelled(): self
    {
        return new self(
            'session_cancelled',
            'This attendance session has been cancelled.',
            409,
        );
    }

    public static function qrInactive(): self
    {
        return new self(
            'attendance_unavailable',
            'QR attendance is not active for this session.',
            409,
        );
    }

    public static function notEnrolled(): self
    {
        return new self(
            'not_enrolled',
            'You are not enrolled in the class for this QR code.',
            403,
        );
    }

    public static function alreadyRecorded(): self
    {
        return new self(
            'already_recorded',
            'Attendance has already been recorded for you in this session.',
            409,
        );
    }
}
