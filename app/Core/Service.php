<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

abstract class Service
{
    protected function normalizeRequiredText(mixed $value, string $message): string
    {
        $text = trim((string) ($value ?? ''));

        if ($text === '') {
            throw new InvalidArgumentException($message);
        }

        return $text;
    }

    protected function normalizeOptionalText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    protected function normalizeRequiredInt(mixed $value, string $message, bool $allowZero = false): int
    {
        if ($value === null || $value === '') {
            throw new InvalidArgumentException($message);
        }

        if (!is_numeric($value)) {
            throw new InvalidArgumentException($message);
        }

        $intValue = (int) $value;

        if($allowZero) {
            if ($intValue < 0) {
                throw new InvalidArgumentException($message);
            }
        } else {
            if ($intValue <= 0) {
                throw new InvalidArgumentException($message);
            }
        }

        return $intValue;
    }

    protected function normalizeOptionalInt(mixed $value, bool $allowZero = false): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        $intValue = (int) $value;

        if($allowZero) {
            if ($intValue < 0) {
                return null;
            }
        } else {
            if ($intValue <= 0) {
                return null;
            }
        }

        return $intValue;
    }

    protected function normalizeRequiredFloat(mixed $value, string $message, bool $allowZero = false): float
    {
        if ($value === null || $value === '') {
            throw new InvalidArgumentException($message);
        }

        if (!is_numeric($value)) {
            throw new InvalidArgumentException('Debe ser numérico.');
        }

        $floatVal = (float) $value;

        if($allowZero) {
            if ($floatVal < 0) {
                throw new InvalidArgumentException($message);
            }
        } else {
            if ($floatVal <= 0) {
                throw new InvalidArgumentException($message);
            }
        }

        return $floatVal;
    }

    protected function normalizeOptionalFloat(mixed $value, bool $allowZero = false): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        $floatVal = (float) $value;

        if($allowZero) {
            if ($floatVal < 0) {
                return null;
            }
        } else {
            if ($floatVal <= 0) {
                return null;
            }
        }

        return $floatVal;
    }

    protected function normalizeBoolToInt(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_numeric($value)) {
            return ((int) $value) === 1 ? 1 : 0;
        }

        $text = strtolower(trim((string) $value));

        return in_array($text, ['1', 'true', 'on', 'yes'], true) ? 1 : 0;
    }

    protected function formatDateToSQL(string $date, string $message = '', bool $optional = true) : ?string {
        if($date === null || $date === '') {
            if($optional)
                return null;
            else
                throw new InvalidArgumentException($message);
        }

        $aux = explode('/', $date);
        
        if(sizeof($aux) != 3) {
            if($optional)
                return null;
            else
                throw new InvalidArgumentException($message);
        }

        return $aux[2] . '-' .
                $aux[1] . '-' .
                $aux[0];
    }

    protected function generateUuidBinary(): string {
        $data = random_bytes(16);

        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant RFC 4122

        return $data;
    }

    function uuidBinaryToString(string $binary): string {
        $hex = bin2hex($binary);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    function uuidStringToBinary(string $uuid): string {
        // die($uuid);
        return hex2bin(str_replace('-', '', $uuid));
    }

    protected function formatTimeTo12h(?string $time24): string {
        if (!$time24) return '';

        $parts = explode(':', $time24);

        if (count($parts) < 2) return '';

        $hours = (int)$parts[0];
        $minutes = $parts[1];

        $period = $hours >= 12 ? 'PM' : 'AM';

        $hours = $hours % 12;
        $hours = $hours === 0 ? 12 : $hours; // 0 → 12

        return "{$hours}:{$minutes} {$period}";
    }

    protected function calculateAge(string $dob): string {
        $aux = \DateTime::createFromFormat('d/m/Y', $dob);
        $hoy = new \DateTime();

        $diff = $hoy->diff($aux);

        $years = $diff->y;
        $months = $diff->m;
        $days = $diff->d;

        if ($years >= 1) {
            return "{$years} años";
        }

        if ($months >= 1) {
            return "0 años {$months} meses";
        }

        return "{$days} días";
    }

    protected function minutesToTime(int $minutes): string {
        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        return sprintf('%02d:%02d', $hours, $mins);
    }

    protected function timeToMinutes(string $time): int {
        if (!preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $time, $matches)) {
            throw new InvalidArgumentException("Formato de hora inválido: {$time}");
        }

        $h = (int) $matches[1];
        $m = (int) $matches[2];

        if ($h < 0 || $h > 23 || $m < 0 || $m > 59) {
            throw new InvalidArgumentException("Hora fuera de rango: {$time}");
        }

        return ($h * 60) + $m;
    }

    function is_uuid(mixed $value): bool {
        return is_string($value)
            && preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                $value
            ) === 1;
    }

    protected function encrypt_hash(string $plain) {
        $options = [
            'cost' => 11
        ];
        return password_hash($plain, PASSWORD_BCRYPT, $options);
    }
}