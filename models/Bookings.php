<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumBookings\Models;

use Osmium\Core\Library\OsmiumPDO;

/**
 * What a theme calls to build its booking form. The form posts to /api/bookings/ (fields type_id, resource_id,
 * date, time, name, email, phone, notes, token and the zip honeypot) and is sent back to the page it came from.
 *
 *     $bookings = new \Osmium\Services\OsmiumBookings\Models\Bookings($this->osmium->dataSource);
 *     $types = $bookings->types();         // id, name, description, minutes, price
 *     $people = $bookings->resources();    // id, name (empty when the site books no one in particular)
 *     $rules = $bookings->rules($this->osmium->config->company->openingHours ?? null);         // hours by weekday (with optional breakStart/breakEnd), minNoticeHours, maxDaysAhead, stepMinutes
 *     $notice = $bookings->pullNotice();   // status (sent or failed) and message, shown once
 */
class Bookings
{
    private const NOTICE_KEY = 'booking_notice';

    public function __construct(private OsmiumPDO $database)
    {
    }

    /**
     * @return list<array{id: int, name: string, description: ?string, minutes: int, price: ?string}>
     */
    public function types(): array
    {
        $types = [];
        foreach ((new BookingType($this->database))->listAll() as $row) {
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
        foreach ((new BookingResource($this->database))->listAll() as $row) {
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
        $config = BookingConfig::get(companyHours: $companyHours);
        unset($config['notifyEmails']); // The team's addresses are not for the page

        return $config;
    }

    /**
     * The message left for this visitor by their last booking request, once
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
