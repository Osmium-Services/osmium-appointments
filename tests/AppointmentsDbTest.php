<?php

declare(strict_types=1);

namespace Tests\Services\OsmiumAppointments;

use Osmium\Services\OsmiumAppointments\Models\Appointment;
use Osmium\Services\OsmiumAppointments\Models\AppointmentAlternatives;
use Osmium\Services\OsmiumAppointments\Models\AppointmentConfig;
use Osmium\Services\OsmiumAppointments\Models\AppointmentResource;
use Osmium\Services\OsmiumAppointments\Models\AppointmentType;
use Osmium\Services\OsmiumAppointments\Models\Appointments;
use Tests\Integration\DatabaseTestCase;

require_once __DIR__ . '/../models/AppointmentConfig.php';
require_once __DIR__ . '/../models/AppointmentType.php';
require_once __DIR__ . '/../models/AppointmentResource.php';
require_once __DIR__ . '/../models/Appointment.php';
require_once __DIR__ . '/../models/AppointmentAlternatives.php';
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

    public function test_no_preference_picks_the_first_person_who_is_free_and_not_on_break(): void
    {
        $appointments = new Appointment($this->db);
        $people = new AppointmentResource($this->db);
        $typeId = $this->type();
        $amy = $people->create(name: 'zzAmy', enabled: true, sortOrder: 1, breakStart: '12:00', breakEnd: '13:00');
        $ben = $people->create(name: 'zzBen', enabled: true, sortOrder: 2, breakStart: '13:00', breakEnd: '14:00');
        $list = [$people->get($amy), $people->get($ben)];

        $this->assertSame($amy, (int) $appointments->findFreeResource($list, '2026-11-09 10:00:00', '2026-11-09 11:00:00')['id']);
        $this->assertSame($ben, (int) $appointments->findFreeResource($list, '2026-11-09 12:00:00', '2026-11-09 13:00:00')['id']);
        $this->assertSame($amy, (int) $appointments->findFreeResource($list, '2026-11-09 13:00:00', '2026-11-09 14:00:00')['id']);

        $busy = $appointments->create($this->request($typeId, $amy), '2026-11-09 10:00:00', '2026-11-09 11:00:00');
        $appointments->setStatus(id: $busy['id'], status: 'confirmed', sequence: 0);
        $this->assertSame($ben, (int) $appointments->findFreeResource($list, '2026-11-09 10:00:00', '2026-11-09 11:00:00')['id']);

        $other = $appointments->create($this->request($typeId, $ben, 'x@example.com'), '2026-11-09 10:00:00', '2026-11-09 11:00:00');
        $appointments->setStatus(id: $other['id'], status: 'confirmed', sequence: 0);
        $this->assertNull($appointments->findFreeResource($list, '2026-11-09 10:00:00', '2026-11-09 11:00:00'));
        $this->assertNull($appointments->findFreeResource($list, '2026-11-09 12:30:00', '2026-11-09 13:30:00'));
    }

    public function test_no_preference_spreads_waiting_requests_across_the_team(): void
    {
        $appointments = new Appointment($this->db);
        $people = new AppointmentResource($this->db);
        $typeId = $this->type();
        $amy = $people->create(name: 'zzAmy', enabled: true, sortOrder: 1);
        $ben = $people->create(name: 'zzBen', enabled: true, sortOrder: 2);
        $list = [$people->get($amy), $people->get($ben)];

        $appointments->create($this->request($typeId, $amy), '2026-11-09 10:00:00', '2026-11-09 11:00:00');

        $this->assertSame($ben, (int) $appointments->findFreeResource($list, '2026-11-09 10:00:00', '2026-11-09 11:00:00')['id']);

        $appointments->create($this->request($typeId, $ben, 'x@example.com'), '2026-11-09 10:00:00', '2026-11-09 11:00:00');

        $this->assertSame($amy, (int) $appointments->findFreeResource($list, '2026-11-09 10:00:00', '2026-11-09 11:00:00')['id']);
    }

    public function test_someone_off_or_outside_their_regular_hours_is_never_free_or_suggested(): void
    {
        $resources = new AppointmentResource($this->db);
        $appointments = new Appointment($this->db);
        $zoe = $resources->create(name: 'zzZoe', enabled: true, sortOrder: 1, weekly: \json_encode([4 => ['start' => '09:00', 'end' => '15:00']]), timeOff: \json_encode([['from' => '2026-11-05', 'to' => '2026-11-05', 'start' => '13:00', 'end' => '17:00', 'reason' => 'Unwell']]));
        $max = $resources->create(name: 'zzMax', enabled: true, sortOrder: 2);
        $people = [$resources->get($zoe), $resources->get($max)];
        $this->assertSame('zzZoe', $people[0]['name']);

        // Thursday 5 Nov 2026: Zoe works until 15:00 but is off from 13:00 (part-day time off)
        $this->assertSame($zoe, (int) $appointments->findFreeResource($people, '2026-11-05 10:00:00', '2026-11-05 11:00:00')['id']);
        $this->assertSame($max, (int) $appointments->findFreeResource($people, '2026-11-05 13:00:00', '2026-11-05 14:00:00')['id']);
        // Thursday 12 Nov: no time off, but nothing after her regular 15:00 finish
        $this->assertSame($max, (int) $appointments->findFreeResource($people, '2026-11-12 15:00:00', '2026-11-12 16:00:00')['id']);
        // Both unavailable: nobody
        $resources->update(id: $max, name: 'zzMax', enabled: true, sortOrder: 2, timeOff: \json_encode([['from' => '2026-11-05', 'to' => '2026-11-05', 'start' => '', 'end' => '', 'reason' => '']]));
        $people = [$resources->get($zoe), $resources->get($max)];
        $this->assertNull($appointments->findFreeResource($people, '2026-11-05 13:00:00', '2026-11-05 14:00:00'));

        $alternatives = new AppointmentAlternatives($appointments, AppointmentConfig::defaults() + ['hours' => []], new \DateTimeImmutable('2026-11-02 08:00'));
        $this->assertFalse($alternatives->isFree($people[0], '2026-11-05 13:00:00', '2026-11-05 14:00:00'));
        $this->assertFalse($alternatives->isFree($people[1], '2026-11-05 09:00:00', '2026-11-05 10:00:00'));
        $this->assertTrue($alternatives->isFree($people[1], '2026-11-06 09:00:00', '2026-11-06 10:00:00'));
    }

    public function test_the_clean_up_after_an_appointment_holds_the_booth_but_is_not_part_of_the_booking(): void
    {
        $resources = new AppointmentResource($this->db);
        $appointments = new Appointment($this->db);
        $booth = $resources->create(name: 'zzBooth 1', enabled: true, sortOrder: 1);
        $sunbed = (new AppointmentType($this->db))->create(['name' => 'zzSunbed', 'description' => null, 'durationMinutes' => 20, 'cleanMinutes' => 10, 'priceText' => null, 'enabled' => true, 'sortOrder' => 0]);
        $this->assertSame(10, (int) (new AppointmentType($this->db))->get($sunbed)['clean_minutes']);

        $first = $appointments->create($this->request($sunbed, $booth), '2026-11-05 10:00:00', '2026-11-05 10:20:00');
        $appointments->setStatus(id: $first['id'], status: 'confirmed', sequence: 0);
        // What the customer sees is still the 20 minute session
        $this->assertSame('2026-11-05 10:20:00', $appointments->get($first['id'])['ends_at']);

        // 10:20 is inside the clean-up, 10:30 is clear (the new one's own clean-up is held after it, not before)
        $this->assertNotNull($appointments->findOverlap(excludeId: 0, resourceId: $booth, startsAt: '2026-11-05 10:20:00', endsAt: '2026-11-05 10:40:00', cleanMinutes: 10));
        $this->assertNotNull($appointments->findOverlap(excludeId: 0, resourceId: $booth, startsAt: '2026-11-05 10:29:00', endsAt: '2026-11-05 10:49:00', cleanMinutes: 10));
        $this->assertNull($appointments->findOverlap(excludeId: 0, resourceId: $booth, startsAt: '2026-11-05 10:30:00', endsAt: '2026-11-05 10:50:00', cleanMinutes: 10));
        // Before it, the new session's own clean-up must finish before 10:00: 09:30 to 09:50 plus 10 is exactly 10:00
        $this->assertNull($appointments->findOverlap(excludeId: 0, resourceId: $booth, startsAt: '2026-11-05 09:30:00', endsAt: '2026-11-05 09:50:00', cleanMinutes: 10));
        $this->assertNotNull($appointments->findOverlap(excludeId: 0, resourceId: $booth, startsAt: '2026-11-05 09:35:00', endsAt: '2026-11-05 09:55:00', cleanMinutes: 10));

        $people = [$resources->get($booth)];
        $this->assertNull($appointments->findFreeResource($people, '2026-11-05 10:20:00', '2026-11-05 10:40:00', 10));
        $this->assertSame($booth, (int) $appointments->findFreeResource($people, '2026-11-05 10:30:00', '2026-11-05 10:50:00', 10)['id']);
    }

    public function test_a_refusal_suggests_someone_else_and_nearby_times(): void
    {
        $appointments = new Appointment($this->db);
        $people = new AppointmentResource($this->db);
        $typeId = $this->type();
        $amy = $people->create(name: 'zzAmy', enabled: true, sortOrder: 1, breakStart: '12:00', breakEnd: '13:00');
        $ben = $people->create(name: 'zzBen', enabled: true, sortOrder: 2);
        $list = [$people->get($amy), $people->get($ben)];

        $day = ['open' => '09:00', 'close' => '17:00', 'breakStart' => null, 'breakEnd' => null];
        $config = AppointmentConfig::defaults() + ['hours' => [1 => $day, 2 => $day, 3 => $day, 4 => $day, 5 => $day, 6 => null, 7 => null]];
        $alternatives = new AppointmentAlternatives($appointments, $config, new \DateTimeImmutable('2026-11-02 08:00'));

        $text = $alternatives->message($list[0], $list, '2026-11-09', '12:15', 60);
        $this->assertStringContainsString('zzAmy is not available at 12:15.', $text);
        $this->assertStringContainsString('zzBen is free then.', $text);
        $this->assertStringContainsString('zzAmy could do 11:00 or 13:00 that day.', $text);

        $busy = $appointments->create($this->request($typeId, $ben), '2026-11-09 12:00:00', '2026-11-09 13:00:00');
        $appointments->setStatus(id: $busy['id'], status: 'confirmed', sequence: 0);

        $nobody = $alternatives->message(null, $list, '2026-11-09', '12:15', 60);
        $this->assertStringContainsString('Nobody is free at 12:15.', $nobody);
        $this->assertStringContainsString('We could do', $nobody);
        $this->assertStringNotContainsString('is free then', $nobody);
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
