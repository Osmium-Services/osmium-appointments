<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumAppointments\Models;

/**
 * Whether a requested time is one the business can take, from its opening hours, notice, how-far-ahead and step.
 * Pure: it is given the settings and "now", so it can be tested on its own.
 */
class AppointmentRules
{
    /**
     * @param array{hours: array<int, array{open: string, close: string}|null>, minNoticeHours: int, maxDaysAhead: int, stepMinutes: int} $config
     * @return array{startsAt: string, endsAt: string} Both as Y-m-d H:i:s
     * @throws \InvalidArgumentException with a message fit to show the customer
     */
    public static function slot(array $config, string $date, string $time, int $durationMinutes, \DateTimeImmutable $now): array
    {
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, $now->getTimezone());
        $startBad = $start === false || $start->format('Y-m-d H:i') !== $date . ' ' . $time;
        if ($startBad) throw new \InvalidArgumentException('Please choose a date and time');

        $end = $start->modify("+{$durationMinutes} minutes");

        $tooSoon = $start < $now->modify('+' . $config['minNoticeHours'] . ' hours');
        if ($tooSoon) throw new \InvalidArgumentException('Please allow at least ' . self::hoursText($config['minNoticeHours']) . ' notice');

        $tooFar = $start > $now->modify('+' . $config['maxDaysAhead'] . ' days');
        if ($tooFar) throw new \InvalidArgumentException('Appointments can be requested up to ' . $config['maxDaysAhead'] . ' days ahead');

        $minutes = (int) $start->format('H') * 60 + (int) $start->format('i');
        $offStep = $minutes % $config['stepMinutes'] !== 0;
        if ($offStep) throw new \InvalidArgumentException('Please choose a time on the ' . $config['stepMinutes'] . ' minute marks');

        $hours = $config['hours'][(int) $start->format('N')] ?? null;
        if ($hours === null) throw new \InvalidArgumentException('We are closed on ' . $start->format('l') . 's');

        $firstTime = $hours['open'];
        $lastTime = $hours['close'];

        $sameDay = $end->format('Y-m-d') === $start->format('Y-m-d');
        $outsideHours = !$sameDay || $start->format('H:i') < $firstTime || $end->format('H:i') > $lastTime;
        if ($outsideHours) throw new \InvalidArgumentException('That time is outside our appointment hours on ' . $start->format('l') . 's (' . $firstTime . ' to ' . $lastTime . ')');

        $breakStart = $hours['breakStart'] ?? null;
        $breakEnd = $hours['breakEnd'] ?? null;
        $hasBreak = $breakStart !== null && $breakEnd !== null;
        $clashesBreak = $hasBreak && $start->format('H:i') < $breakEnd && $end->format('H:i') > $breakStart;
        if ($clashesBreak) throw new \InvalidArgumentException('We are not available between ' . $breakStart . ' and ' . $breakEnd . ' on ' . $start->format('l') . 's');

        return ['startsAt' => $start->format('Y-m-d H:i:s'), 'endsAt' => $end->format('Y-m-d H:i:s')];
    }

    /**
     * The same checks for an appointment made or moved by staff: opening hours, closed days, breaks and the time
     * step, but no minimum notice, how-far-ahead limit or "not in the past" rule (they may enter anything the shop can do).
     *
     * @param array{hours: array<int, array{open: string, close: string}|null>, stepMinutes: int} $config
     * @return array{startsAt: string, endsAt: string}
     * @throws \InvalidArgumentException
     */
    public static function staffSlot(array $config, \DateTimeImmutable $start, int $durationMinutes): array
    {
        $relaxed = ['minNoticeHours' => 0, 'maxDaysAhead' => 100000] + $config;

        return self::slot(config: $relaxed, date: $start->format('Y-m-d'), time: $start->format('H:i'), durationMinutes: $durationMinutes, now: new \DateTimeImmutable('2000-01-01'));
    }

    /**
     * Whether an appointment would run into this person's daily break (break_start and break_end as H:i; none = never)
     *
     * @param array{break_start?: ?string, break_end?: ?string}|null $resource
     */
    public static function onBreak(?array $resource, string $startsAt, string $endsAt): bool
    {
        $breakStart = $resource['break_start'] ?? null;
        $breakEnd = $resource['break_end'] ?? null;
        $hasBreak = $breakStart !== null && $breakEnd !== null && $breakStart !== '' && $breakEnd !== '';
        if (!$hasBreak) return false;

        return \substr($startsAt, 11, 5) < $breakEnd && \substr($endsAt, 11, 5) > $breakStart;
    }

    /**
     * Why this person cannot take an appointment at this time, or null when they can: time off (holiday, sickness),
     * a day or hours they do not regularly work, or their daily break. weekly and time_off are JSON as stored.
     *
     * @param array<string, mixed>|null $resource
     */
    public static function unavailableReason(?array $resource, string $startsAt, string $endsAt): ?string
    {
        if ($resource === null) return null;

        $name = (string) ($resource['name'] ?? 'They');
        $date = \substr($startsAt, 0, 10);
        $from = \substr($startsAt, 11, 5);
        $to = \substr($endsAt, 11, 5);

        foreach (self::decode($resource['time_off'] ?? null) as $off) {
            $started = ($off['from'] ?? '') !== '' && $date >= $off['from'];
            $notOver = ($off['to'] ?? '') === '' || $date <= $off['to'];
            if (!$started || !$notOver) continue;

            $wholeDay = ($off['start'] ?? '') === '' || ($off['end'] ?? '') === '';
            $inWindow = !$wholeDay && $from < $off['end'] && $to > $off['start'];
            if (!$wholeDay && !$inWindow) continue;

            $label = ($off['reason'] ?? '') !== '' ? ' (' . $off['reason'] . ')' : '';
            $span = $wholeDay ? '' : ' from ' . $off['start'] . ' to ' . $off['end'];

            return $name . ' is off on ' . (new \DateTimeImmutable($date))->format('D j M') . $span . $label;
        }

        $weekday = (int) (new \DateTimeImmutable($date))->format('N');
        $regular = self::decode($resource['weekly'] ?? null)[$weekday] ?? null;
        if (\is_array($regular)) {
            $dayName = (new \DateTimeImmutable($date))->format('l');
            if (!empty($regular['off'])) return $name . ' does not work on ' . $dayName . 's';

            $hasHours = ($regular['start'] ?? '') !== '' && ($regular['end'] ?? '') !== '';
            if ($hasHours && ($from < $regular['start'] || $to > $regular['end'])) return $name . ' works ' . $regular['start'] . ' to ' . $regular['end'] . ' on ' . $dayName . 's';
        }

        $onBreak = self::onBreak($resource, $startsAt, $endsAt);
        if ($onBreak) return $name . ' is on their break then (' . $resource['break_start'] . ' to ' . $resource['break_end'] . ')';

        return null;
    }

    /** @return array<int|string, mixed> the stored JSON as an array, empty when there is none or it is not valid */
    public static function decode(mixed $stored): array
    {
        if (\is_array($stored)) return $stored;
        $empty = !\is_string($stored) || $stored === '';
        if ($empty) return [];

        $decoded = \json_decode($stored, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /** Two appointments overlap when each starts before the other ends; touching ends are fine */
    public static function overlaps(string $startA, string $endA, string $startB, string $endB): bool
    {
        return $startA < $endB && $startB < $endA;
    }

    private static function hoursText(int $hours): string
    {
        if ($hours % 24 === 0 && $hours > 0) {
            $days = \intdiv($hours, 24);

            return $days === 1 ? '1 day' : $days . ' days';
        }

        return $hours === 1 ? '1 hour' : $hours . ' hours';
    }
}
