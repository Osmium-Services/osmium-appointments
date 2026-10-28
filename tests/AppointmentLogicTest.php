<?php

declare(strict_types=1);

namespace Tests\Services\OsmiumAppointments;

use Osmium\Services\OsmiumAppointments\Models\AppointmentConfig;
use Osmium\Services\OsmiumAppointments\Models\AppointmentIcs;
use Osmium\Services\OsmiumAppointments\Models\AppointmentMail;
use Osmium\Services\OsmiumAppointments\Models\AppointmentRequest;
use Osmium\Services\OsmiumAppointments\Models\AppointmentRules;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/AppointmentConfig.php';
require_once __DIR__ . '/../models/AppointmentIcs.php';
require_once __DIR__ . '/../models/AppointmentMail.php';
require_once __DIR__ . '/../models/AppointmentRequest.php';
require_once __DIR__ . '/../models/AppointmentRules.php';

/**
 * The rules, request cleaning, calendar file and email words: everything that needs no database.
 */
class AppointmentLogicTest extends TestCase
{
    // A Monday morning
    private const NOW = '2026-11-02 08:00:00';

    /** The rules with Monday to Friday 09:00 to 17:00, as if Company > Location said so */
    private function config(): array
    {
        $day = ['open' => '09:00', 'close' => '17:00', 'breakStart' => null, 'breakEnd' => null];

        return AppointmentConfig::defaults() + ['hours' => [1 => $day, 2 => $day, 3 => $day, 4 => $day, 5 => $day, 6 => null, 7 => null]];
    }

    private function slot(string $date, string $time, int $minutes = 60, ?array $config = null): array
    {
        return AppointmentRules::slot(
            config: $config ?? $this->config(),
            date: $date,
            time: $time,
            durationMinutes: $minutes,
            now: new \DateTimeImmutable(self::NOW),
        );
    }

    private function slotError(string $date, string $time, int $minutes = 60): string
    {
        try {
            $this->slot($date, $time, $minutes);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return '';
    }

    public function test_a_good_time_gives_start_and_end(): void
    {
        $this->assertSame(['startsAt' => '2026-11-05 14:30:00', 'endsAt' => '2026-11-05 15:30:00'], $this->slot('2026-11-05', '14:30'));
    }

    public function test_the_notice_the_closed_days_and_the_hours_are_enforced(): void
    {
        $this->assertStringContainsString('at least 1 day notice', $this->slotError('2026-11-02', '14:00'));
        $this->assertStringContainsString('closed on Saturdays', $this->slotError('2026-11-07', '10:00'));
        $this->assertStringContainsString('outside our appointment hours', $this->slotError('2026-11-05', '08:30'));
        $this->assertStringContainsString('outside our appointment hours', $this->slotError('2026-11-05', '16:30'));
        $this->assertSame('', $this->slotError('2026-11-05', '16:00'));
    }

    public function test_hours_come_from_the_company_and_the_break_is_added(): void
    {
        $company = ['Monday' => ['closed' => false, 'opens' => '08:00', 'closes' => '16:00'], 'Tuesday' => ['closed' => true, 'opens' => '09:00', 'closes' => '17:00']];
        $breaks = [1 => ['start' => '12:00', 'end' => '13:00']] + AppointmentConfig::defaults()['breaks'];
        $hours = AppointmentConfig::hoursFor($company, $breaks);

        $this->assertSame(['open' => '08:00', 'close' => '16:00', 'breakStart' => '12:00', 'breakEnd' => '13:00'], $hours[1]);
        $this->assertNull($hours[2]);
        $this->assertNull($hours[3]);
        $this->assertNull(AppointmentConfig::hoursFor(null, $breaks)[1]);
    }

    public function test_a_break_blocks_overlapping_times_only(): void
    {
        $config = $this->config();
        $config['hours'] = [4 => ['open' => '09:00', 'close' => '17:00', 'breakStart' => '12:00', 'breakEnd' => '13:00']];

        $this->assertSame('2026-11-05 11:00:00', $this->slot('2026-11-05', '11:00', 60, $config)['startsAt']);
        $this->assertSame('2026-11-05 13:00:00', $this->slot('2026-11-05', '13:00', 60, $config)['startsAt']);
        foreach (['11:30', '12:00', '12:45'] as $time) {
            try {
                $this->slot('2026-11-05', $time, 60, $config);
                $this->fail($time . ' should clash with the break');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('12:00 and 13:00', $e->getMessage());
            }
        }
    }

    public function test_a_bookable_window_narrows_the_opening_hours_but_never_widens_them(): void
    {
        $company = ['Monday' => ['closed' => false, 'opens' => '09:00', 'closes' => '17:00'], 'Tuesday' => ['closed' => false, 'opens' => '09:00', 'closes' => '17:00'], 'Wednesday' => ['closed' => false, 'opens' => '09:00', 'closes' => '12:00']];
        $windows = [1 => ['start' => '09:30', 'end' => '16:30'], 2 => ['start' => '08:00', 'end' => '18:00'], 3 => ['start' => '13:00', 'end' => '15:00']] + AppointmentConfig::defaults()['windows'];
        $hours = AppointmentConfig::hoursFor($company, AppointmentConfig::defaults()['breaks'], $windows);

        $this->assertSame(['09:30', '16:30'], [$hours[1]['open'], $hours[1]['close']]);
        $this->assertSame(['09:00', '17:00'], [$hours[2]['open'], $hours[2]['close']]);
        $this->assertNull($hours[3]);

        $config = $this->config();
        $config['hours'] = $hours;
        foreach (['09:00', '16:00'] as $time) {
            try {
                $this->slot('2026-11-09', $time, 60, $config);
                $this->fail($time . ' should be outside the appointment hours');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('09:30 to 16:30', $e->getMessage());
            }
        }
        $this->assertSame('2026-11-09 09:30:00', $this->slot('2026-11-09', '09:30', 60, $config)['startsAt']);
    }

    public function test_a_bookable_window_must_start_before_it_ends(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AppointmentConfig::validate(['windows' => [1 => ['start' => '16:00', 'end' => '10:00']], 'stepMinutes' => 15, 'minNoticeHours' => 1, 'maxDaysAhead' => 30]);
    }

    public function test_a_persons_break_blocks_overlapping_appointments_only(): void
    {
        $amy = ['break_start' => '12:00', 'break_end' => '13:00'];

        $this->assertFalse(AppointmentRules::onBreak($amy, '2026-11-09 11:00:00', '2026-11-09 12:00:00'));
        $this->assertFalse(AppointmentRules::onBreak($amy, '2026-11-09 13:00:00', '2026-11-09 14:00:00'));
        $this->assertTrue(AppointmentRules::onBreak($amy, '2026-11-09 11:30:00', '2026-11-09 12:15:00'));
        $this->assertTrue(AppointmentRules::onBreak($amy, '2026-11-09 12:30:00', '2026-11-09 13:30:00'));
        $this->assertFalse(AppointmentRules::onBreak(['break_start' => null, 'break_end' => null], '2026-11-09 12:30:00', '2026-11-09 13:30:00'));
        $this->assertFalse(AppointmentRules::onBreak(null, '2026-11-09 12:30:00', '2026-11-09 13:30:00'));
    }

    public function test_time_off_regular_hours_and_breaks_make_a_person_unavailable(): void
    {
        $ella = [
            'name' => 'Ella',
            'break_start' => '12:00',
            'break_end' => '13:00',
            'weekly' => \json_encode([1 => ['start' => '09:00', 'end' => '15:00'], 6 => ['off' => true]]),
            'time_off' => \json_encode([
                ['from' => '2026-11-10', 'to' => '2026-11-11', 'start' => '', 'end' => '', 'reason' => 'Holiday'],
                ['from' => '2026-11-12', 'to' => '2026-11-12', 'start' => '14:00', 'end' => '17:00', 'reason' => ''],
            ]),
        ];

        // Monday 9 Nov: works 09:00 to 15:00
        $this->assertNull(AppointmentRules::unavailableReason($ella, '2026-11-09 10:00:00', '2026-11-09 11:00:00'));
        $this->assertStringContainsString('works 09:00 to 15:00', (string) AppointmentRules::unavailableReason($ella, '2026-11-09 14:30:00', '2026-11-09 15:30:00'));
        $this->assertStringContainsString('on their break', (string) AppointmentRules::unavailableReason($ella, '2026-11-09 11:30:00', '2026-11-09 12:30:00'));
        // Saturday is a day off
        $this->assertStringContainsString('does not work on Saturdays', (string) AppointmentRules::unavailableReason($ella, '2026-11-14 10:00:00', '2026-11-14 11:00:00'));
        // Whole-day holiday, with its reason
        $this->assertStringContainsString('(Holiday)', (string) AppointmentRules::unavailableReason($ella, '2026-11-11 10:00:00', '2026-11-11 11:00:00'));
        $this->assertNull(AppointmentRules::unavailableReason($ella, '2026-11-13 10:00:00', '2026-11-13 11:00:00'));
        // Part day: off from 14:00 on the 12th only
        $this->assertNull(AppointmentRules::unavailableReason($ella, '2026-11-12 10:00:00', '2026-11-12 11:00:00'));
        $this->assertStringContainsString('from 14:00 to 17:00', (string) AppointmentRules::unavailableReason($ella, '2026-11-12 13:30:00', '2026-11-12 14:30:00'));
        // Nobody chosen, or nothing set: always available
        $this->assertNull(AppointmentRules::unavailableReason(null, '2026-11-11 10:00:00', '2026-11-11 11:00:00'));
        $this->assertNull(AppointmentRules::unavailableReason(['name' => 'Ben'], '2026-11-11 10:00:00', '2026-11-11 11:00:00'));
    }

    public function test_staff_can_book_any_date_but_not_outside_the_opening_hours(): void
    {
        // A past date and one years away are fine for staff (the website's notice and horizon limits do not apply)
        $past = AppointmentRules::staffSlot($this->config(), new \DateTimeImmutable('2025-03-03 10:00'), 60);
        $this->assertSame('2025-03-03 10:00:00', $past['startsAt']);
        $far = AppointmentRules::staffSlot($this->config(), new \DateTimeImmutable('2031-03-03 10:00'), 60);
        $this->assertSame('2031-03-03 11:00:00', $far['endsAt']);

        foreach (['2026-11-04 08:45', '2026-11-04 16:30', '2026-11-08 10:00'] as $bad) {
            try {
                AppointmentRules::staffSlot($this->config(), new \DateTimeImmutable($bad), 60);
                $this->fail("{$bad} should be refused");
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_a_decline_or_cancel_email_carries_the_message_and_the_phone_number(): void
    {
        $appointment = ['customer_name' => 'Pat', 'type_name' => 'Cut', 'resource_name' => 'Amy', 'starts_at' => '2026-11-05 14:00:00', 'ends_at' => '2026-11-05 15:00:00', 'reference' => 'ABC1234567'];

        foreach (['declined', 'cancelled'] as $kind) {
            $mail = AppointmentMail::$kind(appointment: $appointment, siteName: 'Shop', message: "Dan is free at 3pm <b>Thursday</b>\nSorry", phone: '01234 567890');
            $this->assertStringContainsString('Dan is free at 3pm &lt;b&gt;Thursday&lt;/b&gt;', $mail['html'], "{$kind}: the message is escaped");
            $this->assertStringContainsString('white-space:pre-line', $mail['html'], "{$kind}: line breaks are kept");
            $this->assertStringContainsString('please call us on 01234 567890', $mail['html'], "{$kind}: phone line");

            $plain = AppointmentMail::$kind(appointment: $appointment, siteName: 'Shop');
            $this->assertStringNotContainsString('white-space:pre-line', $plain['html'], "{$kind}: no message, no message paragraph");
            $this->assertStringNotContainsString('please call us', $plain['html'], "{$kind}: no phone set, no call sentence");
            $this->assertStringContainsString('send a new request on our website', $plain['html']);
        }
    }

    public function test_a_break_must_start_before_it_ends(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AppointmentConfig::validate(['breaks' => [1 => ['start' => '13:00', 'end' => '12:00']], 'stepMinutes' => 15, 'minNoticeHours' => 1, 'maxDaysAhead' => 30]);
    }

    public function test_the_step_the_horizon_and_bad_input_are_enforced(): void
    {
        $this->assertStringContainsString('15 minute marks', $this->slotError('2026-11-05', '10:10'));
        $this->assertStringContainsString('up to 90 days ahead', $this->slotError('2027-03-01', '10:00'));
        $this->assertStringContainsString('choose a date and time', $this->slotError('2026-02-30', '10:00'));
        $this->assertStringContainsString('choose a date and time', $this->slotError('tomorrow', '10:00'));
    }

    public function test_overlap_ignores_touching_ends(): void
    {
        $this->assertTrue(AppointmentRules::overlaps('2026-11-05 10:00:00', '2026-11-05 11:00:00', '2026-11-05 10:30:00', '2026-11-05 11:30:00'));
        $this->assertFalse(AppointmentRules::overlaps('2026-11-05 10:00:00', '2026-11-05 11:00:00', '2026-11-05 11:00:00', '2026-11-05 12:00:00'));
    }

    public function test_settings_are_validated_saved_and_read_back(): void
    {
        $path = \sys_get_temp_dir() . '/appointments-' . \uniqid() . '.json.php';
        $this->assertSame(AppointmentConfig::defaults(), AppointmentConfig::stored($path));

        $saved = AppointmentConfig::save([
            'breaks' => [1 => ['start' => '12:00', 'end' => '13:00'], 2 => ['start' => '', 'end' => '']],
            'stepMinutes' => '30', 'minNoticeHours' => '48', 'maxDaysAhead' => '60', 'notifyEmails' => "a@example.com, b@example.com\na@example.com",
        ], $path);

        $this->assertSame(['start' => '12:00', 'end' => '13:00'], $saved['breaks'][1]);
        $this->assertNull($saved['breaks'][2]);
        $this->assertNull($saved['breaks'][4]);
        $this->assertSame(['a@example.com', 'b@example.com'], AppointmentConfig::stored($path)['notifyEmails']);
        $this->assertSame(30, AppointmentConfig::stored($path)['stepMinutes']);

        $company = ['Monday' => ['closed' => false, 'opens' => '10:00', 'closes' => '14:00']];
        $this->assertSame(['open' => '10:00', 'close' => '14:00', 'breakStart' => '12:00', 'breakEnd' => '13:00'], AppointmentConfig::get($path, $company)['hours'][1]);
        \unlink($path);
    }

    public function test_bad_settings_are_refused(): void
    {
        foreach ([
            ['breaks' => [1 => ['start' => '17:00', 'end' => '09:00']], 'stepMinutes' => 15, 'minNoticeHours' => 1, 'maxDaysAhead' => 5, 'notifyEmails' => ''],
            ['breaks' => [], 'stepMinutes' => 7, 'minNoticeHours' => 1, 'maxDaysAhead' => 5, 'notifyEmails' => ''],
            ['breaks' => [], 'stepMinutes' => 15, 'minNoticeHours' => 1, 'maxDaysAhead' => 5, 'notifyEmails' => 'not-an-email'],
            ['breaks' => [], 'stepMinutes' => 15, 'minNoticeHours' => 1, 'maxDaysAhead' => 0, 'notifyEmails' => ''],
        ] as $settings) {
            try {
                AppointmentConfig::validate($settings);
                $this->fail('Expected the settings to be refused');
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_a_request_is_cleaned_and_bad_ones_refused(): void
    {
        $good = AppointmentRequest::parse(['type_id' => '3', 'resource_id' => '0', 'date' => ' 2026-11-05 ', 'time' => '14:30', 'name' => ' Pat ', 'email' => 'pat@example.com', 'phone' => '', 'notes' => '']);
        $this->assertSame(3, $good['typeId']);
        $this->assertNull($good['resourceId']);
        $this->assertNull($good['phone']);
        $this->assertSame('Pat', $good['name']);
        $this->assertSame('2026-11-05', $good['date']);

        $base = ['type_id' => '3', 'name' => 'Pat', 'email' => 'pat@example.com'];
        foreach ([['name' => ''], ['email' => 'nope'], ['type_id' => '0'], ['phone' => 'call me maybe'], ['notes' => \str_repeat('x', 1001)]] as $change) {
            try {
                AppointmentRequest::parse($change + $base);
                $this->fail('Expected the request to be refused');
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_the_calendar_file_has_a_stable_uid_and_a_rising_sequence(): void
    {
        $appointment = ['reference' => 'ABC234DEFG', 'starts_at' => '2026-11-05 14:30:00', 'ends_at' => '2026-11-05 15:30:00', 'status' => 'confirmed', 'ics_sequence' => 2];
        $stamp = new \DateTimeImmutable('2026-11-02 08:00:00', new \DateTimeZone('Europe/London'));

        $file = AppointmentIcs::build($appointment, 'Haircut, with Sam; at Acme', 'Ref ABC', 'example.com', $stamp);

        $this->assertStringContainsString("UID:ABC234DEFG@example.com\r\n", $file);
        $this->assertStringContainsString("SEQUENCE:2\r\n", $file);
        $this->assertStringContainsString("DTSTART:20261105T143000Z\r\n", $file); // November: UK is on GMT
        $this->assertStringContainsString('SUMMARY:Haircut\\, with Sam\; at Acme', $file);
        $this->assertStringContainsString("STATUS:CONFIRMED\r\n", $file);
        $this->assertStringContainsString("METHOD:PUBLISH\r\n", $file);

        $cancelled = AppointmentIcs::build(['status' => 'cancelled', 'ics_sequence' => 3] + $appointment, 'x', 'y', 'example.com', $stamp);
        $this->assertStringContainsString("STATUS:CANCELLED\r\n", $cancelled);
        $this->assertStringContainsString("METHOD:CANCEL\r\n", $cancelled);
    }

    public function test_summer_time_is_converted_and_long_lines_are_folded(): void
    {
        $zone = new \DateTimeZone('Europe/London');
        $this->assertSame('20260705T133000Z', AppointmentIcs::utc('2026-07-05 14:30:00', $zone)); // BST is one hour ahead of UTC

        $appointment = ['reference' => 'ABC234DEFG', 'starts_at' => '2026-07-05 14:30:00', 'ends_at' => '2026-07-05 15:30:00', 'status' => 'confirmed', 'ics_sequence' => 0];
        $file = AppointmentIcs::build($appointment, \str_repeat('Long title ', 20), 'y', 'example.com', new \DateTimeImmutable('now', $zone));
        foreach (\explode("\r\n", $file) as $line) {
            $this->assertLessThanOrEqual(75, \strlen($line));
        }
    }

    public function test_the_google_link_carries_the_event(): void
    {
        $appointment = ['starts_at' => '2026-11-05 14:30:00', 'ends_at' => '2026-11-05 15:30:00'];
        $link = AppointmentIcs::googleLink($appointment, 'Haircut at Acme', 'Ref ABC', new \DateTimeZone('Europe/London'));

        $this->assertStringStartsWith('https://calendar.google.com/calendar/render?action=TEMPLATE', $link);
        $this->assertStringContainsString('dates=20261105T143000Z%2F20261105T153000Z', $link);
        $this->assertStringContainsString('text=Haircut%20at%20Acme', $link);
    }

    public function test_emails_escape_what_the_customer_typed(): void
    {
        $appointment = ['customer_name' => '<b>Pat</b>', 'customer_email' => 'pat@example.com', 'customer_phone' => null, 'customer_notes' => '<script>x</script>', 'type_name' => 'Cut & colour', 'resource_name' => 'Sam', 'starts_at' => '2026-11-05 14:30:00', 'ends_at' => '2026-11-05 15:30:00', 'reference' => 'ABC234DEFG'];

        $received = AppointmentMail::received($appointment, 'Acme');
        $team = AppointmentMail::requestForTeam($appointment, 'Acme', 'https://example.com/admin/appointments/');

        $this->assertStringNotContainsString('<b>Pat', $received['html']);
        $this->assertStringContainsString('Cut &amp; colour', $received['html']);
        $this->assertStringContainsString('Thursday 5 November 2026, 14:30 to 15:30', $received['html']);
        $this->assertStringContainsString('ABC234DEFG', $received['html']);
        $this->assertStringNotContainsString('<script>', $team['html']);

        $confirmed = AppointmentMail::confirmed($appointment, 'Acme', 'https://example.com/api/appointments-calendar?ref=ABC', 'https://calendar.google.com/x?a=1&b=2');
        $this->assertStringContainsString('<a href="https://example.com/api/appointments-calendar?ref=ABC">', $confirmed['html']);
        $this->assertStringContainsString('a=1&amp;b=2', $confirmed['html']);
        $this->assertStringNotContainsString('&lt;a href', $confirmed['html']);
        $this->assertStringContainsString('<a href="https://example.com/admin/appointments/">', $team['html']);
        $this->assertStringContainsString('Sam', $team['html']);
    }
}
