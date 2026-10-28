<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumAppointments\Models;

/**
 * The dashboard card: how many requests are waiting for an answer, and the first few.
 */
class AppointmentDashboard
{
    /**
     * @param array{osmium: object, role?: string} $payload
     * @return array<string, mixed>
     */
    public static function widget(array $payload): array
    {
        $model = new Appointment($payload['osmium']->dataSource);
        $waiting = \array_values(\array_filter($model->listAll(), static fn(array $row): bool => $row['status'] === 'requested'));

        $lines = [];
        foreach (\array_slice($waiting, 0, 5) as $row) {
            $lines[] = [
                'text' => (string) $row['customer_name'] . ', ' . (string) $row['type_name'],
                'sub' => (new \DateTimeImmutable($row['starts_at']))->format('D j M, H:i'),
                'route' => 'appointments',
            ];
        }

        return [
            'id' => 'osmium-appointments',
            'title' => 'Appointment requests',
            'icon' => 'bx-calendar-check',
            'roles' => ['admin'],
            'order' => 20,
            'count' => \count($waiting),
            'link' => ['label' => 'Open appointments', 'route' => 'appointments'],
            'lines' => $lines,
            'emptyText' => 'No requests waiting.',
        ];
    }
}
