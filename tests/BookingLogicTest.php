<?php

declare(strict_types=1);

namespace Tests\Services\OsmiumBookings;

use Osmium\Services\OsmiumBookings\Models\BookingConfig;
use Osmium\Services\OsmiumBookings\Models\BookingIcs;
use Osmium\Services\OsmiumBookings\Models\BookingMail;
use Osmium\Services\OsmiumBookings\Models\BookingRequest;
use Osmium\Services\OsmiumBookings\Models\BookingRules;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/BookingConfig.php';
require_once __DIR__ . '/../models/BookingIcs.php';
require_once __DIR__ . '/../models/BookingMail.php';
require_once __DIR__ . '/../models/BookingRequest.php';
require_once __DIR__ . '/../models/BookingRules.php';

/**
 * The rules, request cleaning, calendar file and email words: everything that needs no database.
 */
class BookingLogicTest extends TestCase
{
    // A Monday morning
    private const NOW = '2026-11-02 08:00:00';

    /** The rules with Monday to Friday 09:00 to 17:00, as if Company > Location said so */
    private function config(): array
    {
        $day = ['open' => '09:00', 'close' => '17:00', 'breakStart' => null, 'breakEnd' => null];

        return BookingConfig::defaults() + ['hours' => [1 => $day, 2 => $day, 3 => $day, 4 => $day, 5 => $day, 6 => null, 7 => null]];
    }

    private function slot(string $date, string $time, int $minutes = 60, ?array $config = null): array
    {
        return BookingRules::slot(
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
        $this->assertStringContainsString('outside our booking hours', $this->slotError('2026-11-05', '08:30'));
        $this->assertStringContainsString('outside our booking hours', $this->slotError('2026-11-05', '16:30'));
        $this->assertSame('', $this->slotError('2026-11-05', '16:00'));
    }

    public function test_hours_come_from_the_company_and_the_break_is_added(): void
    {
        $company = ['Monday' => ['closed' => false, 'opens' => '08:00', 'closes' => '16:00'], 'Tuesday' => ['closed' => true, 'opens' => '09:00', 'closes' => '17:00']];
        $breaks = [1 => ['start' => '12:00', 'end' => '13:00']] + BookingConfig::defaults()['breaks'];
        $hours = BookingConfig::hoursFor($company, $breaks);

        $this->assertSame(['open' => '08:00', 'close' => '16:00', 'breakStart' => '12:00', 'breakEnd' => '13:00'], $hours[1]);
        $this->assertNull($hours[2]);
        $this->assertNull($hours[3]);
        $this->assertNull(BookingConfig::hoursFor(null, $breaks)[1]);
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
        $windows = [1 => ['start' => '09:30', 'end' => '16:30'], 2 => ['start' => '08:00', 'end' => '18:00'], 3 => ['start' => '13:00', 'end' => '15:00']] + BookingConfig::defaults()['windows'];
        $hours = BookingConfig::hoursFor($company, BookingConfig::defaults()['breaks'], $windows);

        $this->assertSame(['09:30', '16:30'], [$hours[1]['open'], $hours[1]['close']]);
        $this->assertSame(['09:00', '17:00'], [$hours[2]['open'], $hours[2]['close']]);
        $this->assertNull($hours[3]);

        $config = $this->config();
        $config['hours'] = $hours;
        foreach (['09:00', '16:00'] as $time) {
            try {
                $this->slot('2026-11-09', $time, 60, $config);
                $this->fail($time . ' should be outside the booking hours');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('09:30 to 16:30', $e->getMessage());
            }
        }
        $this->assertSame('2026-11-09 09:30:00', $this->slot('2026-11-09', '09:30', 60, $config)['startsAt']);
    }

    public function test_a_bookable_window_must_start_before_it_ends(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BookingConfig::validate(['windows' => [1 => ['start' => '16:00', 'end' => '10:00']], 'stepMinutes' => 15, 'minNoticeHours' => 1, 'maxDaysAhead' => 30]);
    }

    public function test_a_break_must_start_before_it_ends(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BookingConfig::validate(['breaks' => [1 => ['start' => '13:00', 'end' => '12:00']], 'stepMinutes' => 15, 'minNoticeHours' => 1, 'maxDaysAhead' => 30]);
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
        $this->assertTrue(BookingRules::overlaps('2026-11-05 10:00:00', '2026-11-05 11:00:00', '2026-11-05 10:30:00', '2026-11-05 11:30:00'));
        $this->assertFalse(BookingRules::overlaps('2026-11-05 10:00:00', '2026-11-05 11:00:00', '2026-11-05 11:00:00', '2026-11-05 12:00:00'));
    }

    public function test_settings_are_validated_saved_and_read_back(): void
    {
        $path = \sys_get_temp_dir() . '/bookings-' . \uniqid() . '.json.php';
        $this->assertSame(BookingConfig::defaults(), BookingConfig::stored($path));

        $saved = BookingConfig::save([
            'breaks' => [1 => ['start' => '12:00', 'end' => '13:00'], 2 => ['start' => '', 'end' => '']],
            'stepMinutes' => '30', 'minNoticeHours' => '48', 'maxDaysAhead' => '60', 'notifyEmails' => "a@example.com, b@example.com\na@example.com",
        ], $path);

        $this->assertSame(['start' => '12:00', 'end' => '13:00'], $saved['breaks'][1]);
        $this->assertNull($saved['breaks'][2]);
        $this->assertNull($saved['breaks'][4]);
        $this->assertSame(['a@example.com', 'b@example.com'], BookingConfig::stored($path)['notifyEmails']);
        $this->assertSame(30, BookingConfig::stored($path)['stepMinutes']);

        $company = ['Monday' => ['closed' => false, 'opens' => '10:00', 'closes' => '14:00']];
        $this->assertSame(['open' => '10:00', 'close' => '14:00', 'breakStart' => '12:00', 'breakEnd' => '13:00'], BookingConfig::get($path, $company)['hours'][1]);
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
                BookingConfig::validate($settings);
                $this->fail('Expected the settings to be refused');
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_a_request_is_cleaned_and_bad_ones_refused(): void
    {
        $good = BookingRequest::parse(['type_id' => '3', 'resource_id' => '0', 'date' => ' 2026-11-05 ', 'time' => '14:30', 'name' => ' Pat ', 'email' => 'pat@example.com', 'phone' => '', 'notes' => '']);
        $this->assertSame(3, $good['typeId']);
        $this->assertNull($good['resourceId']);
        $this->assertNull($good['phone']);
        $this->assertSame('Pat', $good['name']);
        $this->assertSame('2026-11-05', $good['date']);

        $base = ['type_id' => '3', 'name' => 'Pat', 'email' => 'pat@example.com'];
        foreach ([['name' => ''], ['email' => 'nope'], ['type_id' => '0'], ['phone' => 'call me maybe'], ['notes' => \str_repeat('x', 1001)]] as $change) {
            try {
                BookingRequest::parse($change + $base);
                $this->fail('Expected the request to be refused');
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_the_calendar_file_has_a_stable_uid_and_a_rising_sequence(): void
    {
        $booking = ['reference' => 'ABC234DEFG', 'starts_at' => '2026-11-05 14:30:00', 'ends_at' => '2026-11-05 15:30:00', 'status' => 'confirmed', 'ics_sequence' => 2];
        $stamp = new \DateTimeImmutable('2026-11-02 08:00:00', new \DateTimeZone('Europe/London'));

        $file = BookingIcs::build($booking, 'Haircut, with Sam; at Acme', 'Ref ABC', 'example.com', $stamp);

        $this->assertStringContainsString("UID:ABC234DEFG@example.com\r\n", $file);
        $this->assertStringContainsString("SEQUENCE:2\r\n", $file);
        $this->assertStringContainsString("DTSTART:20261105T143000Z\r\n", $file); // November: UK is on GMT
        $this->assertStringContainsString('SUMMARY:Haircut\\, with Sam\; at Acme', $file);
        $this->assertStringContainsString("STATUS:CONFIRMED\r\n", $file);
        $this->assertStringContainsString("METHOD:PUBLISH\r\n", $file);

        $cancelled = BookingIcs::build(['status' => 'cancelled', 'ics_sequence' => 3] + $booking, 'x', 'y', 'example.com', $stamp);
        $this->assertStringContainsString("STATUS:CANCELLED\r\n", $cancelled);
        $this->assertStringContainsString("METHOD:CANCEL\r\n", $cancelled);
    }

    public function test_summer_time_is_converted_and_long_lines_are_folded(): void
    {
        $zone = new \DateTimeZone('Europe/London');
        $this->assertSame('20260705T133000Z', BookingIcs::utc('2026-07-05 14:30:00', $zone)); // BST is one hour ahead of UTC

        $booking = ['reference' => 'ABC234DEFG', 'starts_at' => '2026-07-05 14:30:00', 'ends_at' => '2026-07-05 15:30:00', 'status' => 'confirmed', 'ics_sequence' => 0];
        $file = BookingIcs::build($booking, \str_repeat('Long title ', 20), 'y', 'example.com', new \DateTimeImmutable('now', $zone));
        foreach (\explode("\r\n", $file) as $line) {
            $this->assertLessThanOrEqual(75, \strlen($line));
        }
    }

    public function test_the_google_link_carries_the_event(): void
    {
        $booking = ['starts_at' => '2026-11-05 14:30:00', 'ends_at' => '2026-11-05 15:30:00'];
        $link = BookingIcs::googleLink($booking, 'Haircut at Acme', 'Ref ABC', new \DateTimeZone('Europe/London'));

        $this->assertStringStartsWith('https://calendar.google.com/calendar/render?action=TEMPLATE', $link);
        $this->assertStringContainsString('dates=20261105T143000Z%2F20261105T153000Z', $link);
        $this->assertStringContainsString('text=Haircut%20at%20Acme', $link);
    }

    public function test_emails_escape_what_the_customer_typed(): void
    {
        $booking = ['customer_name' => '<b>Pat</b>', 'customer_email' => 'pat@example.com', 'customer_phone' => null, 'customer_notes' => '<script>x</script>', 'type_name' => 'Cut & colour', 'resource_name' => 'Sam', 'starts_at' => '2026-11-05 14:30:00', 'ends_at' => '2026-11-05 15:30:00', 'reference' => 'ABC234DEFG'];

        $received = BookingMail::received($booking, 'Acme');
        $team = BookingMail::requestForTeam($booking, 'Acme', 'https://example.com/admin/bookings/');

        $this->assertStringNotContainsString('<b>Pat', $received['html']);
        $this->assertStringContainsString('Cut &amp; colour', $received['html']);
        $this->assertStringContainsString('Thursday 5 November 2026, 14:30 to 15:30', $received['html']);
        $this->assertStringContainsString('ABC234DEFG', $received['html']);
        $this->assertStringNotContainsString('<script>', $team['html']);

        $confirmed = BookingMail::confirmed($booking, 'Acme', 'https://example.com/api/bookings-calendar?ref=ABC', 'https://calendar.google.com/x?a=1&b=2');
        $this->assertStringContainsString('<a href="https://example.com/api/bookings-calendar?ref=ABC">', $confirmed['html']);
        $this->assertStringContainsString('a=1&amp;b=2', $confirmed['html']);
        $this->assertStringNotContainsString('&lt;a href', $confirmed['html']);
        $this->assertStringContainsString('<a href="https://example.com/admin/bookings/">', $team['html']);
        $this->assertStringContainsString('Sam', $team['html']);
    }
}
