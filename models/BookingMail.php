<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumBookings\Models;

/**
 * The words of every email a booking sends. Free of the database and the mailer, so they can be tested on their own.
 * Each method returns the subject and the HTML body.
 */
class BookingMail
{
    /**
     * @param array<string, mixed> $booking A booking row with type_name and resource_name
     * @return array{subject: string, html: string}
     */
    public static function received(array $booking, string $siteName): array
    {
        return self::compose(
            siteName: $siteName,
            subject: 'We have your booking request',
            heading: 'Thanks, ' . $booking['customer_name'],
            paragraphs: [
                'We have received your request and will confirm it shortly. Nothing is booked until you hear from us.',
                self::details($booking),
            ],
        );
    }

    /**
     * @return array{subject: string, html: string}
     */
    public static function confirmed(array $booking, string $siteName, string $icsUrl, string $googleUrl): array
    {
        return self::compose(
            siteName: $siteName,
            subject: 'Your booking is confirmed',
            heading: 'You are booked in, ' . $booking['customer_name'],
            paragraphs: [
                self::details($booking),
                '<p>Add it to your calendar: <a href="' . self::e($googleUrl) . '">Google Calendar</a> or <a href="' . self::e($icsUrl) . '">any other calendar (.ics file)</a>.</p>',
                'If you need to change or cancel, just reply to this email.',
            ],
        );
    }

    /**
     * @return array{subject: string, html: string}
     */
    public static function changed(array $booking, string $siteName, string $icsUrl, string $googleUrl): array
    {
        return self::compose(
            siteName: $siteName,
            subject: 'Your booking has changed',
            heading: 'Your booking has been updated',
            paragraphs: [
                self::details($booking),
                '<p>Please update your calendar: <a href="' . self::e($googleUrl) . '">Google Calendar</a> or <a href="' . self::e($icsUrl) . '">any other calendar (.ics file)</a>. Remove the earlier entry if your calendar keeps both.</p>',
                'If this does not suit you, just reply to this email.',
            ],
        );
    }

    /**
     * @return array{subject: string, html: string}
     */
    public static function declined(array $booking, string $siteName): array
    {
        return self::compose(
            siteName: $siteName,
            subject: 'About your booking request',
            heading: 'Sorry, ' . $booking['customer_name'],
            paragraphs: [
                'We are not able to take this booking:',
                self::details($booking),
                'Please reply to this email or send a new request for another time.',
            ],
        );
    }

    /**
     * @return array{subject: string, html: string}
     */
    public static function cancelled(array $booking, string $siteName): array
    {
        return self::compose(
            siteName: $siteName,
            subject: 'Your booking is cancelled',
            heading: 'Your booking is cancelled',
            paragraphs: [
                self::details($booking),
                'If you did not expect this, or would like another time, please reply to this email.',
            ],
        );
    }

    /**
     * The note to the business when a request arrives
     *
     * @return array{subject: string, html: string}
     */
    public static function requestForTeam(array $booking, string $siteName, string $adminUrl): array
    {
        $contact = self::e((string) $booking['customer_name']) . ', ' . self::e((string) $booking['customer_email'])
            . ($booking['customer_phone'] ? ', ' . self::e((string) $booking['customer_phone']) : '');
        $notes = !empty($booking['customer_notes']) ? '<p><strong>Their message:</strong><br>' . \nl2br(self::e((string) $booking['customer_notes'])) . '</p>' : '';

        return self::compose(
            siteName: $siteName,
            subject: 'New booking request from ' . $booking['customer_name'],
            heading: 'New booking request',
            paragraphs: [
                self::details($booking),
                '<p><strong>From:</strong> ' . $contact . '</p>' . $notes,
                '<p><a href="' . self::e($adminUrl) . '">Confirm or decline it in the admin</a></p>',
            ],
        );
    }

    /** The one-line summary used as a calendar event's title */
    public static function eventTitle(array $booking, string $siteName): string
    {
        return $booking['type_name'] . ' at ' . $siteName;
    }

    /** "Friday 6 November 2026, 14:30 to 15:30" */
    public static function when(array $booking): string
    {
        $start = new \DateTimeImmutable($booking['starts_at']);
        $end = new \DateTimeImmutable($booking['ends_at']);

        return $start->format('l j F Y, H:i') . ' to ' . $end->format('H:i');
    }

    private static function details(array $booking): string
    {
        $rows = [
            'What' => (string) $booking['type_name'],
            'When' => self::when($booking),
            'Reference' => (string) $booking['reference'],
        ];
        $hasResource = !empty($booking['resource_name']);
        if ($hasResource) $rows = ['What' => $rows['What'], 'With' => (string) $booking['resource_name']] + $rows;

        $html = '<table cellpadding="4" style="border-collapse:collapse">';
        foreach ($rows as $label => $value) {
            $html .= '<tr><td><strong>' . $label . '</strong></td><td>' . self::e($value) . '</td></tr>';
        }

        return $html . '</table>';
    }

    /**
     * @param list<string> $paragraphs
     * @return array{subject: string, html: string}
     */
    private static function compose(string $siteName, string $subject, string $heading, array $paragraphs): array
    {
        $body = '';
        foreach ($paragraphs as $paragraph) {
            $isMarkup = \str_starts_with($paragraph, '<');
            $body .= $isMarkup ? $paragraph : '<p>' . self::e($paragraph) . '</p>';
        }

        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#222;max-width:560px">'
            . '<h2 style="margin:0 0 12px">' . self::e($heading) . '</h2>' . $body
            . '<p style="color:#777;margin-top:24px">' . self::e($siteName) . '</p></div>';

        return ['subject' => $subject, 'html' => $html];
    }

    private static function e(string $text): string
    {
        return \htmlspecialchars($text, ENT_QUOTES);
    }
}
