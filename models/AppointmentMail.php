<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumAppointments\Models;

/**
 * The words of every email an appointment sends. Free of the database and the mailer, so they can be tested on their own.
 * Each method returns the subject and the HTML body.
 */
class AppointmentMail
{
    /**
     * @param array<string, mixed> $appointment An appointment row with type_name and resource_name
     * @return array{subject: string, html: string}
     */
    public static function received(array $appointment, string $siteName): array
    {
        return self::compose(
            siteName: $siteName,
            subject: 'We have your appointment request',
            heading: 'Thanks, ' . $appointment['customer_name'],
            paragraphs: [
                'We have received your request and will confirm it shortly. Nothing is booked until you hear from us.',
                self::details($appointment),
            ],
        );
    }

    /**
     * @return array{subject: string, html: string}
     */
    public static function confirmed(array $appointment, string $siteName, string $icsUrl, string $googleUrl): array
    {
        return self::compose(
            siteName: $siteName,
            subject: 'Your appointment is confirmed',
            heading: 'You are booked in, ' . $appointment['customer_name'],
            paragraphs: [
                self::details($appointment),
                '<p>Add it to your calendar: <a href="' . self::e($googleUrl) . '">Google Calendar</a> or <a href="' . self::e($icsUrl) . '">any other calendar (.ics file)</a>.</p>',
                'If you need to change or cancel, just reply to this email.',
            ],
        );
    }

    /**
     * @return array{subject: string, html: string}
     */
    public static function changed(array $appointment, string $siteName, string $icsUrl, string $googleUrl): array
    {
        return self::compose(
            siteName: $siteName,
            subject: 'Your appointment has changed',
            heading: 'Your appointment has been updated',
            paragraphs: [
                self::details($appointment),
                '<p>Please update your calendar: <a href="' . self::e($googleUrl) . '">Google Calendar</a> or <a href="' . self::e($icsUrl) . '">any other calendar (.ics file)</a>. Remove the earlier entry if your calendar keeps both.</p>',
                'If this does not suit you, just reply to this email.',
            ],
        );
    }

    /**
     * @return array{subject: string, html: string}
     */
    public static function declined(array $appointment, string $siteName, string $message = '', string $phone = ''): array
    {
        return self::compose(
            siteName: $siteName,
            subject: 'About your appointment request',
            heading: 'Sorry, ' . $appointment['customer_name'],
            paragraphs: \array_values(\array_filter([
                'We are not able to take this appointment:',
                self::details($appointment),
                $message !== '' ? '<p style="white-space:pre-line">' . self::e($message) . '</p>' : '',
                $phone !== '' ? 'To arrange another time, please call us on ' . $phone . ' or send a new request on our website.' : 'To arrange another time, please send a new request on our website.',
            ], static fn(string $paragraph): bool => $paragraph !== '')),
        );
    }

    /**
     * @return array{subject: string, html: string}
     */
    public static function cancelled(array $appointment, string $siteName, string $message = '', string $phone = ''): array
    {
        return self::compose(
            siteName: $siteName,
            subject: 'Your appointment is cancelled',
            heading: 'Your appointment is cancelled',
            paragraphs: \array_values(\array_filter([
                self::details($appointment),
                $message !== '' ? '<p style="white-space:pre-line">' . self::e($message) . '</p>' : '',
                $phone !== '' ? 'To arrange another time, please call us on ' . $phone . ' or send a new request on our website.' : 'To arrange another time, please send a new request on our website.',
            ], static fn(string $paragraph): bool => $paragraph !== '')),
        );
    }

    /**
     * The note to the business when a request arrives
     *
     * @return array{subject: string, html: string}
     */
    public static function requestForTeam(array $appointment, string $siteName, string $adminUrl): array
    {
        $contact = self::e((string) $appointment['customer_name']) . ', ' . self::e((string) $appointment['customer_email'])
            . ($appointment['customer_phone'] ? ', ' . self::e((string) $appointment['customer_phone']) : '');
        $notes = !empty($appointment['customer_notes']) ? '<p><strong>Their message:</strong><br>' . \nl2br(self::e((string) $appointment['customer_notes'])) . '</p>' : '';

        return self::compose(
            siteName: $siteName,
            subject: 'New appointment request from ' . $appointment['customer_name'],
            heading: 'New appointment request',
            paragraphs: [
                self::details($appointment),
                '<p><strong>From:</strong> ' . $contact . '</p>' . $notes,
                '<p><a href="' . self::e($adminUrl) . '">Confirm or decline it in the admin</a></p>',
            ],
        );
    }

    /** The one-line summary used as a calendar event's title */
    public static function eventTitle(array $appointment, string $siteName): string
    {
        return $appointment['type_name'] . ' at ' . $siteName;
    }

    /** "Friday 6 November 2026, 14:30 to 15:30" */
    public static function when(array $appointment): string
    {
        $start = new \DateTimeImmutable($appointment['starts_at']);
        $end = new \DateTimeImmutable($appointment['ends_at']);

        return $start->format('l j F Y, H:i') . ' to ' . $end->format('H:i');
    }

    private static function details(array $appointment): string
    {
        $rows = [
            'What' => (string) $appointment['type_name'],
            'When' => self::when($appointment),
            'Reference' => (string) $appointment['reference'],
        ];
        $hasResource = !empty($appointment['resource_name']);
        if ($hasResource) $rows = ['What' => $rows['What'], 'With' => (string) $appointment['resource_name']] + $rows;

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
