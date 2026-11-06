<?php
declare(strict_types=1);
namespace OCA\Stammbaum\Service;
final class Values
{
    public static function text(mixed $value, string $field, int $max, bool $required = false): string
    {
        if (
            !is_string($value) ||
            mb_strlen($value) > $max ||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)
        ) {
            throw new Problem('validation', 'Check the text length and characters.', 422, [
                'field' => $field,
            ]);
        }
        $value = trim($value);
        if ($required && $value === '') {
            throw new Problem('validation', 'This field is required.', 422, ['field' => $field]);
        }
        return $value;
    }
    public static function choice(mixed $value, array $allowed, string $field): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new Problem('validation', 'Choose a supported value.', 422, ['field' => $field]);
        }
        return $value;
    }
    public static function partial(string $value): void
    {
        if (!preg_match('/^(\d{4})(?:-(\d{2})(?:-(\d{2}))?)?$/D', $value, $m) || (int) $m[1] < 1) {
            throw new Problem('invalid_date', 'Use YYYY, YYYY-MM or YYYY-MM-DD, or choose literal wording.');
        }
        if (isset($m[2]) && ((int) $m[2] < 1 || (int) $m[2] > 12)) {
            throw new Problem('invalid_date', 'The month is not valid.');
        }
        if (isset($m[3]) && !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new Problem('invalid_date', 'This calendar date is not valid.');
        }
    }
    /** Bounds are used only for validation, never substituted for recorded precision. */
    private static function boundary(string $date, bool $upper): string
    {
        $parts = explode('-', $date);
        $year = (int) $parts[0];
        $month = isset($parts[1]) ? (int) $parts[1] : ($upper ? 12 : 1);
        $days = [
            31,
            $year % 4 === 0 && ($year % 100 !== 0 || $year % 400 === 0) ? 29 : 28,
            31,
            30,
            31,
            30,
            31,
            31,
            30,
            31,
            30,
            31,
        ];
        $day = isset($parts[2]) ? (int) $parts[2] : ($upper ? $days[$month - 1] : 1);
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
    public static function date(mixed $input): array
    {
        if (!is_array($input) || array_is_list($input)) {
            throw new Problem('invalid_date', 'Provide a date object.');
        }
        $kind = self::choice(
            $input['kind'] ?? 'exact',
            ['exact', 'about', 'before', 'after', 'between', 'period', 'calculated', 'estimated', 'literal'],
            'dateKind',
        );
        $value = self::text($input['value'] ?? '', 'date', 250);
        $end = self::text($input['end'] ?? '', 'dateEnd', 10);
        if ($kind === 'literal') {
            return ['kind' => $kind, 'value' => $value, 'end' => ''];
        }
        if ($value !== '') {
            self::partial($value);
        }
        if ($end !== '') {
            self::partial($end);
        }
        if (!in_array($kind, ['between', 'period'], true) && $end !== '') {
            throw new Problem('invalid_date', 'This date form has no end value.');
        }
        if ($kind === 'between' && ($value === '' || $end === '')) {
            throw new Problem('invalid_date', 'A between date needs both boundaries.');
        }
        if (
            $value !== '' &&
            $end !== '' &&
            strcmp(self::boundary($value, false), self::boundary($end, true)) > 0
        ) {
            throw new Problem('invalid_date', 'The end must not precede the start.');
        }
        return ['kind' => $kind, 'value' => $value, 'end' => $end];
    }
    public static function person(array $b): array
    {
        $given = self::text($b['givenName'] ?? '', 'givenName', 200);
        $family = self::text($b['familyName'] ?? '', 'familyName', 200);
        if ($given === '' && $family === '' && ($b['confirmUnnamed'] ?? false) !== true) {
            throw new Problem('unnamed_confirmation', 'Confirm that this person has no known name.');
        }
        return [
            'givenName' => $given,
            'familyName' => $family,
            'displayNote' => self::text($b['displayNote'] ?? '', 'displayNote', 2000),
            'livingStatus' => self::choice(
                $b['livingStatus'] ?? 'unknown',
                ['living', 'deceased', 'unknown'],
                'livingStatus',
            ),
            'sex' => self::choice($b['sex'] ?? 'unknown', ['female', 'male', 'unknown', 'other'], 'sex'),
            'visibility' => self::choice(
                $b['visibility'] ?? 'members',
                ['members', 'management'],
                'visibility',
            ),
            'birthDate' => self::date($b['birthDate'] ?? ['kind' => 'exact', 'value' => '', 'end' => '']),
            'deathDate' => self::date($b['deathDate'] ?? ['kind' => 'exact', 'value' => '', 'end' => '']),
            'birthPlace' => self::text($b['birthPlace'] ?? '', 'birthPlace', 500),
            'deathPlace' => self::text($b['deathPlace'] ?? '', 'deathPlace', 500),
        ];
    }
}
