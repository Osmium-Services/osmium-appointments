<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumAppointments\Models;

/**
 * What a good receptionist would say when the time asked for is taken: someone else who is free then, or the nearest
 * times the same person (or anyone, when nobody was asked for) could do the same day. All text is written for the customer.
 */
class AppointmentAlternatives
{
    private const MAX_TIMES = 2;

    /**
     * @param array<string, mixed> $config the rules from AppointmentConfig::get() (with hours)
     */
    public function __construct(private Appointment $appointments, private array $config, private \DateTimeImmutable $now)
    {
    }

    /**
     * Free means working then (no time off, within their regular hours, not on their break) and no confirmed appointment overlapping. A null lane is the business as a whole.
     *
     * @param array<string, mixed>|null $lane a person row, or null
     */
    public function isFree(?array $lane, string $startsAt, string $endsAt): bool
    {
        $unavailable = AppointmentRules::unavailableReason($lane, $startsAt, $endsAt) !== null;
        if ($unavailable) return false;

        $laneId = $lane !== null ? (int) $lane['id'] : null;

        return $this->appointments->findOverlap(excludeId: 0, resourceId: $laneId, startsAt: $startsAt, endsAt: $endsAt) === null;
    }

    /**
     * The refusal to show the customer, with what else could work
     *
     * @param array<string, mixed>|null $chosen the person asked for, null for no preference
     * @param list<array<string, mixed>> $people every enabled person
     */
    public function message(?array $chosen, array $people, string $date, string $time, int $minutes): string
    {
        $start = new \DateTimeImmutable($date . ' ' . $time);
        $end = $start->modify("+{$minutes} minutes");
        $opening = $chosen !== null ? $chosen['name'] . ' is not available at ' . $time . '.' : ($people !== [] ? 'Nobody is free at ' . $time . '.' : 'Sorry, ' . $time . ' is already taken.');

        $others = [];
        foreach ($people as $person) {
            $isChosen = $chosen !== null && (int) $person['id'] === (int) $chosen['id'];
            $free = !$isChosen && $this->isFree($person, $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'));
            if ($free) $others[] = (string) $person['name'];
        }

        $lanes = $chosen !== null ? [$chosen] : ($people !== [] ? $people : [null]);
        $times = $this->nearestTimes(lanes: $lanes, date: $date, requested: $time, minutes: $minutes);

        $parts = [$opening];
        if ($others !== []) $parts[] = $this->join($others) . ($others === [$others[0]] ? ' is' : ' are') . ' free then.';
        if ($times !== []) $parts[] = ($chosen !== null ? $chosen['name'] . ' could do ' : 'We could do ') . $this->join($times, 'or') . ' that day.';
        if ($others === [] && $times === []) $parts[] = 'Please try another day.';

        return \implode(' ', $parts);
    }

    /**
     * The start times closest to the one asked for on the same day that suit at least one lane: one earlier and one later where possible
     *
     * @param list<array<string, mixed>|null> $lanes
     * @return list<string> H:i, in time order
     */
    private function nearestTimes(array $lanes, string $date, string $requested, int $minutes): array
    {
        $hours = $this->config['hours'][(int) (new \DateTimeImmutable($date))->format('N')] ?? null;
        if ($hours === null) return [];

        $step = (int) $this->config['stepMinutes'];
        $wanted = $this->minutesOf($requested);
        $candidates = [];
        for ($at = $this->minutesOf($hours['open']); $at + $minutes <= $this->minutesOf($hours['close']); $at += $step) {
            if ($at % $step !== 0 || $at === $wanted) continue;

            $time = \sprintf('%02d:%02d', \intdiv($at, 60), $at % 60);
            if ($this->suits($lanes, $date, $time, $minutes)) $candidates[$at] = \abs($at - $wanted);
        }

        // The closest one either side of the time asked for; when only one side has any, the two closest on that side
        $before = \array_filter(\array_keys($candidates), static fn(int $at): bool => $at < $wanted);
        $after = \array_filter(\array_keys($candidates), static fn(int $at): bool => $at > $wanted);
        \rsort($before);
        \sort($after);
        $nearest = [];
        if ($before !== [] && $after !== []) $nearest = [$before[0], $after[0]];
        if ($before === [] || $after === []) $nearest = \array_slice(\array_merge($before, $after), 0, self::MAX_TIMES);
        \sort($nearest);

        return \array_map(static fn(int $at): string => \sprintf('%02d:%02d', \intdiv($at, 60), $at % 60), $nearest);
    }

    /**
     * @param list<array<string, mixed>|null> $lanes
     */
    private function suits(array $lanes, string $date, string $time, int $minutes): bool
    {
        try {
            $slot = AppointmentRules::slot(config: $this->config, date: $date, time: $time, durationMinutes: $minutes, now: $this->now);
        } catch (\InvalidArgumentException $e) {
            return false;
        }

        foreach ($lanes as $lane) {
            if ($this->isFree($lane, $slot['startsAt'], $slot['endsAt'])) return true;
        }

        return false;
    }

    private function minutesOf(string $time): int
    {
        return (int) \substr($time, 0, 2) * 60 + (int) \substr($time, 3, 2);
    }

    /**
     * @param list<string> $items
     */
    private function join(array $items, string $last = 'and'): string
    {
        if (\count($items) < 2) return $items[0] ?? '';

        $final = \array_pop($items);

        return \implode(', ', $items) . ' ' . $last . ' ' . $final;
    }
}
