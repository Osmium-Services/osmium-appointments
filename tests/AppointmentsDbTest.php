<?php

declare(strict_types=1);

namespace Tests\Services\OsmiumAppointments;

use Osmium\Services\OsmiumAppointments\Models\Appointment;
use Osmium\Services\OsmiumAppointments\Models\AppointmentResource;
use Osmium\Services\OsmiumAppointments\Models\AppointmentType;
use Osmium\Services\OsmiumAppointments\Models\Appointments;
use Tests\Integration\DatabaseTestCase;

require_once __DIR__ . '/../models/AppointmentConfig.php';
require_once __DIR__ . '/../models/AppointmentType.php';
require_once __DIR__ . '/../models/AppointmentResource.php';
require_once __DIR__ . '/../models/Appointment.php';
require_once __DIR__ . '/../models/Appointments.php';

/**
 * The appointment tables against the installed schema (rolled back with each test; the service must be installed,
 * because creating the tables here would commit the test's transaction).
 */
class AppointmentsDbTest extends DatabaseTestCase
{
    private function type(string $name = 'zzCut', int $minutes = 60, bool $enabled = true): int
    {
        return (new AppointmentType($this->db))->create(['name' => $name, 'description' => null, 'durationMinutes' => $minutes, 'priceText' => null, 'enabled' => $enabled, 'sortOrder' => 0]);
    }

    private function request(int $typeId, ?int $resourceId = null, string $email = 'pat@example.com'): array
    {
        return ['typeId' => $typeId, 'resourceId' => $resourceId, 'name' => 'Pat', 'email' => $email, 'phone' => null, 'notes' => null];
    }

    public function test_a_request_is_stored_as_requested_with_a_unique_reference(): void
    {
        $appointments = new Appointment($this->db);
        $typeId = $this->type();

        $first = $appointments->create($this->request($typeId), '2026-11-05 14:00:00', '2026-11-05 15:00:00');
        $second = $appointments->create($this->request($typeId, null, 'sam@example.com'), '2026-11-05 16:00:00', '2026-11-05 17:00:00');

        $row = $appointments->get($first['id']);
        $this->assertSame('requested', $row['status']);
        $this->assertSame('zzCut', $row['type_name']);
        $this->assertSame(10, \strlen($first['reference']));
        $this->assertNotSame($first['reference'], $second['reference']);
        $this->assertSame($first['id'], (int) $appointments->getByReference($first['reference'])['id']);
    }

    public function test_the_same_person_asking_twice_for_the_same_time_is_a_duplicate(): void
    {
        $appointments = new Appointment($this->db);
        $typeId = $this->type();
        $appointments->create($this->request($typeId), '2026-11-05 14:00:00', '2026-11-05 15:00:00');

        $this->assertTrue($appointments->isDuplicate('pat@example.com', '2026-11-05 14:00:00', $typeId));
        $this->assertFalse($appointments->isDuplicate('pat@example.com', '2026-11-05 15:00:00', $typeId));
        $this->assertFalse($appointments->isDuplicate('other@example.com', '2026-11-05 14:00:00', $typeId));
    }

    public function test_only_a_confirmed_appointment_for_the_same_person_clashes(): void
    {
        $appointments = new Appointment($this->db);
        $typeId = $this->type();
        $resourceId = (new AppointmentResource($this->db))->create(name: 'zzSam', enabled: true, sortOrder: 0);
        $otherId = (new AppointmentResource($this->db))->create(name: 'zzAlex', enabled: true, sortOrder: 0);

        $confirmed = $appointments->create($this->request($typeId, $resourceId), '2026-11-05 14:00:00', '2026-11-05 15:00:00');
        $appointments->setStatus(id: $confirmed['id'], status: 'confirmed', sequence: 0);
        $waiting = $appointments->create($this->request($typeId, $resourceId, 'x@example.com'), '2026-11-05 14:30:00', '2026-11-05 15:30:00');

        $this->assertNotNull($appointments->findOverlap(excludeId: $waiting['id'], resourceId: $resourceId, startsAt: '2026-11-05 14:30:00', endsAt: '2026-11-05 15:30:00'));
        $this->assertNull($appointments->findOverlap(excludeId: $waiting['id'], resourceId: $otherId, startsAt: '2026-11-05 14:30:00', endsAt: '2026-11-05 15:30:00'));
        $this->assertNull($appointments->findOverlap(excludeId: $waiting['id'], resourceId: null, startsAt: '2026-11-05 14:30:00', endsAt: '2026-11-05 15:30:00'));
        $this->assertNull($appointments->findOverlap(excludeId: $waiting['id'], resourceId: $resourceId, startsAt: '2026-11-05 15:00:00', endsAt: '2026-11-05 16:00:00'));
        $this->assertNull($appointments->findOverlap(excludeId: $confirmed['id'], resourceId: $resourceId, startsAt: '2026-11-05 14:00:00', endsAt: '2026-11-05 15:00:00'));
    }

    public function test_appointments_with_no_one_in_particular_clash_with_each_other(): void
    {
        $appointments = new Appointment($this->db);
        $typeId = $this->type();
        $confirmed = $appointments->create($this->request($typeId), '2026-11-05 14:00:00', '2026-11-05 15:00:00');
        $appointments->setStatus(id: $confirmed['id'], status: 'confirmed', sequence: 0);

        $this->assertNotNull($appointments->findOverlap(excludeId: 0, resourceId: null, startsAt: '2026-11-05 14:30:00', endsAt: '2026-11-05 15:30:00'));
    }

    public function test_the_bin_hides_a_appointment_until_it_is_restored(): void
    {
        $appointments = new Appointment($this->db);
        $created = $appointments->create($this->request($this->type()), '2026-11-05 14:00:00', '2026-11-05 15:00:00');
        $appointments->softDelete($created['id']);

        $this->assertNotContains($created['id'], \array_map('intval', \array_column($appointments->listAll(), 'id')));
        $this->assertContains($created['id'], \array_map('intval', \array_column($appointments->listDeleted(), 'id')));
        $this->assertNull($appointments->getByReference($created['reference']));

        $appointments->restore($created['id']);
        $this->assertContains($created['id'], \array_map('intval', \array_column($appointments->listAll(), 'id')));
    }

    public function test_other_services_keep_their_own_reference_on_a_appointment(): void
    {
        $appointments = new Appointment($this->db);
        $created = $appointments->create($this->request($this->type()), '2026-11-05 14:00:00', '2026-11-05 15:00:00');

        $appointments->setExternalRef($created['id'], 'google-calendar', 'evt_1');
        $appointments->setExternalRef($created['id'], 'other-service', ['id' => 7]);
        $appointments->setExternalRef($created['id'], 'google-calendar', 'evt_2');

        $this->assertSame(['google-calendar' => 'evt_2', 'other-service' => ['id' => 7]], Appointment::externalRefs($appointments->get($created['id'])));
    }

    public function test_the_theme_sees_only_things_that_are_turned_on(): void
    {
        $this->type('zzOn', 30, true);
        $this->type('zzOff', 30, false);
        (new AppointmentResource($this->db))->create(name: 'zzHidden', enabled: false, sortOrder: 0);

        $theme = new Appointments($this->db);
        $names = \array_column($theme->types(), 'name');

        $this->assertContains('zzOn', $names);
        $this->assertNotContains('zzOff', $names);
        $this->assertNotContains('zzHidden', \array_column($theme->resources(), 'name'));
        $this->assertArrayNotHasKey('notifyEmails', $theme->rules());
    }

    public function test_a_notice_is_shown_once(): void
    {
        $session = [];
        Appointments::rememberNotice($session, 'sent', 'Thanks');
        $theme = new Appointments($this->db);

        $this->assertSame(['status' => 'sent', 'message' => 'Thanks'], $theme->pullNotice($session));
        $this->assertNull($theme->pullNotice($session));
    }
}
