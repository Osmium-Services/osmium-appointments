<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumAppointments\Models;

use Osmium\Core\Library\OsmiumPDO;

/**
 * What a theme calls to build its appointment form. The form posts to /api/appointments/ (fields type_id, resource_id,
 * date, time, name, email, phone, notes, token and the zip honeypot) and is sent back to the page it came from.
 *
 *     $appointments = new \Osmium\Services\OsmiumAppointments\Models\Appointments($this->osmium->dataSource);
 *     $types = $appointments->types();         // id, name, description, minutes, price
 *     $people = $appointments->resources();    // id, name (empty when the site books no one in particular)
 *     $rules = $appointments->rules($this->osmium->config->company->openingHours ?? null);         // hours by weekday (with optional breakStart/breakEnd), minNoticeHours, maxDaysAhead, stepMinutes
 *     $notice = $appointments->pullNotice();   // status (sent or failed) and message, shown once
 */
class Appointments
{
    private const NOTICE_KEY = 'appointment_notice';

    public function __construct(private OsmiumPDO $database)
    {
    }

    /**
     * @return list<array{id: int, name: string, description: ?string, minutes: int, price: ?string}>
     */
    public function types(): array
    {
        $types = [];
        foreach ((new AppointmentType($this->database))->listAll() as $row) {
            if (!$row['enabled']) continue;
            $types[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'description' => $row['description'] ?? null,
                'minutes' => (int) $row['duration_minutes'],
                'price' => $row['price_text'] ?? null,
            ];
        }

        return $types;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function resources(): array
    {
        $resources = [];
        foreach ((new AppointmentResource($this->database))->listAll() as $row) {
            if (!$row['enabled']) continue;
            $resources[] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
        }

        return $resources;
    }

    /**
     * @param mixed $companyHours pass $this->osmium->config->company->openingHours so the hours are the ones in Company > Location
     * @return array{hours: array<int, array{open: string, close: string}|null>, minNoticeHours: int, maxDaysAhead: int, stepMinutes: int}
     */
    public function rules(mixed $companyHours = null): array
    {
        $config = AppointmentConfig::get(companyHours: $companyHours);
        unset($config['notifyEmails']); // The team's addresses are not for the page

        return $config;
    }

    /**
     * The message left for this visitor by their last appointment request, once
     *
     * @return array{status: string, message: string}|null
     */
    public function pullNotice(?array &$session = null): ?array
    {
        $session ??= $_SESSION;
        $notice = $session[self::NOTICE_KEY] ?? null;
        unset($session[self::NOTICE_KEY]);
        $this->clearSessionNotice();

        return \is_array($notice) ? $notice : null;
    }

    public static function rememberNotice(array &$session, string $status, string $message): void
    {
        $session[self::NOTICE_KEY] = ['status' => $status, 'message' => $message];
    }

    private function clearSessionNotice(): void
    {
        unset($_SESSION[self::NOTICE_KEY]);
    }
}
