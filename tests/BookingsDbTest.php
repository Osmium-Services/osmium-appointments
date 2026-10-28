<?php

declare(strict_types=1);

namespace Tests\Services\OsmiumBookings;

use Osmium\Services\OsmiumBookings\Models\Booking;
use Osmium\Services\OsmiumBookings\Models\BookingResource;
use Osmium\Services\OsmiumBookings\Models\BookingType;
use Osmium\Services\OsmiumBookings\Models\Bookings;
use Tests\Integration\DatabaseTestCase;

require_once __DIR__ . '/../models/BookingConfig.php';
require_once __DIR__ . '/../models/BookingType.php';
require_once __DIR__ . '/../models/BookingResource.php';
require_once __DIR__ . '/../models/Booking.php';
require_once __DIR__ . '/../models/Bookings.php';

/**
 * The booking tables against the installed schema (rolled back with each test; the service must be installed,
 * because creating the tables here would commit the test's transaction).
 */
class BookingsDbTest extends DatabaseTestCase
{
    private function type(string $name = 'zzCut', int $minutes = 60, bool $enabled = true): int
    {
        return (new BookingType($this->db))->create(['name' => $name, 'description' => null, 'durationMinutes' => $minutes, 'priceText' => null, 'enabled' => $enabled, 'sortOrder' => 0]);
    }

    private function request(int $typeId, ?int $resourceId = null, string $email = 'pat@example.com'): array
    {
        return ['typeId' => $typeId, 'resourceId' => $resourceId, 'name' => 'Pat', 'email' => $email, 'phone' => null, 'notes' => null];
    }

    public function test_a_request_is_stored_as_requested_with_a_unique_reference(): void
    {
        $bookings = new Booking($this->db);
        $typeId = $this->type();

        $first = $bookings->create($this->request($typeId), '2026-11-05 14:00:00', '2026-11-05 15:00:00');
        $second = $bookings->create($this->request($typeId, null, 'sam@example.com'), '2026-11-05 16:00:00', '2026-11-05 17:00:00');

        $row = $bookings->get($first['id']);
        $this->assertSame('requested', $row['status']);
        $this->assertSame('zzCut', $row['type_name']);
        $this->assertSame(10, \strlen($first['reference']));
        $this->assertNotSame($first['reference'], $second['reference']);
        $this->assertSame($first['id'], (int) $bookings->getByReference($first['reference'])['id']);
    }

    public function test_the_same_person_asking_twice_for_the_same_time_is_a_duplicate(): void
    {
        $bookings = new Booking($this->db);
        $typeId = $this->type();
        $bookings->create($this->request($typeId), '2026-11-05 14:00:00', '2026-11-05 15:00:00');

        $this->assertTrue($bookings->isDuplicate('pat@example.com', '2026-11-05 14:00:00', $typeId));
        $this->assertFalse($bookings->isDuplicate('pat@example.com', '2026-11-05 15:00:00', $typeId));
        $this->assertFalse($bookings->isDuplicate('other@example.com', '2026-11-05 14:00:00', $typeId));
    }

    public function test_only_a_confirmed_booking_for_the_same_person_clashes(): void
    {
        $bookings = new Booking($this->db);
        $typeId = $this->type();
        $resourceId = (new BookingResource($this->db))->create(name: 'zzSam', enabled: true, sortOrder: 0);
        $otherId = (new BookingResource($this->db))->create(name: 'zzAlex', enabled: true, sortOrder: 0);

        $confirmed = $bookings->create($this->request($typeId, $resourceId), '2026-11-05 14:00:00', '2026-11-05 15:00:00');
        $bookings->setStatus(id: $confirmed['id'], status: 'confirmed', sequence: 0);
        $waiting = $bookings->create($this->request($typeId, $resourceId, 'x@example.com'), '2026-11-05 14:30:00', '2026-11-05 15:30:00');

        $this->assertNotNull($bookings->findOverlap(excludeId: $waiting['id'], resourceId: $resourceId, startsAt: '2026-11-05 14:30:00', endsAt: '2026-11-05 15:30:00'));
        $this->assertNull($bookings->findOverlap(excludeId: $waiting['id'], resourceId: $otherId, startsAt: '2026-11-05 14:30:00', endsAt: '2026-11-05 15:30:00'));
        $this->assertNull($bookings->findOverlap(excludeId: $waiting['id'], resourceId: null, startsAt: '2026-11-05 14:30:00', endsAt: '2026-11-05 15:30:00'));
        $this->assertNull($bookings->findOverlap(excludeId: $waiting['id'], resourceId: $resourceId, startsAt: '2026-11-05 15:00:00', endsAt: '2026-11-05 16:00:00'));
        $this->assertNull($bookings->findOverlap(excludeId: $confirmed['id'], resourceId: $resourceId, startsAt: '2026-11-05 14:00:00', endsAt: '2026-11-05 15:00:00'));
    }

    public function test_bookings_with_no_one_in_particular_clash_with_each_other(): void
    {
        $bookings = new Booking($this->db);
        $typeId = $this->type();
        $confirmed = $bookings->create($this->request($typeId), '2026-11-05 14:00:00', '2026-11-05 15:00:00');
        $bookings->setStatus(id: $confirmed['id'], status: 'confirmed', sequence: 0);

        $this->assertNotNull($bookings->findOverlap(excludeId: 0, resourceId: null, startsAt: '2026-11-05 14:30:00', endsAt: '2026-11-05 15:30:00'));
    }

    public function test_the_bin_hides_a_booking_until_it_is_restored(): void
    {
        $bookings = new Booking($this->db);
        $created = $bookings->create($this->request($this->type()), '2026-11-05 14:00:00', '2026-11-05 15:00:00');
        $bookings->softDelete($created['id']);

        $this->assertNotContains($created['id'], \array_map('intval', \array_column($bookings->listAll(), 'id')));
        $this->assertContains($created['id'], \array_map('intval', \array_column($bookings->listDeleted(), 'id')));
        $this->assertNull($bookings->getByReference($created['reference']));

        $bookings->restore($created['id']);
        $this->assertContains($created['id'], \array_map('intval', \array_column($bookings->listAll(), 'id')));
    }

    public function test_other_services_keep_their_own_reference_on_a_booking(): void
    {
        $bookings = new Booking($this->db);
        $created = $bookings->create($this->request($this->type()), '2026-11-05 14:00:00', '2026-11-05 15:00:00');

        $bookings->setExternalRef($created['id'], 'google-calendar', 'evt_1');
        $bookings->setExternalRef($created['id'], 'other-service', ['id' => 7]);
        $bookings->setExternalRef($created['id'], 'google-calendar', 'evt_2');

        $this->assertSame(['google-calendar' => 'evt_2', 'other-service' => ['id' => 7]], Booking::externalRefs($bookings->get($created['id'])));
    }

    public function test_the_theme_sees_only_things_that_are_turned_on(): void
    {
        $this->type('zzOn', 30, true);
        $this->type('zzOff', 30, false);
        (new BookingResource($this->db))->create(name: 'zzHidden', enabled: false, sortOrder: 0);

        $theme = new Bookings($this->db);
        $names = \array_column($theme->types(), 'name');

        $this->assertContains('zzOn', $names);
        $this->assertNotContains('zzOff', $names);
        $this->assertNotContains('zzHidden', \array_column($theme->resources(), 'name'));
        $this->assertArrayNotHasKey('notifyEmails', $theme->rules());
    }

    public function test_a_notice_is_shown_once(): void
    {
        $session = [];
        Bookings::rememberNotice($session, 'sent', 'Thanks');
        $theme = new Bookings($this->db);

        $this->assertSame(['status' => 'sent', 'message' => 'Thanks'], $theme->pullNotice($session));
        $this->assertNull($theme->pullNotice($session));
    }
}
