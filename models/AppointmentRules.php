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
