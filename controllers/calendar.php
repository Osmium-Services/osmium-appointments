<?php

use Osmium\Services\OsmiumBookings\Models\Booking;
use Osmium\Services\OsmiumBookings\Models\BookingIcs;
use Osmium\Services\OsmiumBookings\Models\BookingMail;

/**
 * Calendar file for a booking (GET /api/bookings-calendar/?ref=REFERENCE). The reference is the key: ten characters
 * chosen at random, only in the customer's emails. The file holds the event, the time and the reference, never the
 * customer's details. A requested or declined booking is not an event, so it answers 404.
 */

$reference = \preg_replace('/[^A-Za-z0-9]/', '', (string) ($_GET['ref'] ?? ''));
$booking = $reference !== '' ? (new Booking($this->osmium->dataSource))->getByReference($reference) : null;

$isEvent = $booking !== null && \in_array($booking['status'], ['confirmed', 'cancelled'], true);
if (!$isEvent) {
    \http_response_code(404);
    echo 'Not found';
    exit;
}

$siteName = (string) $this->osmium->config->site->name;
$host = (string) \parse_url($this->osmium->config->site->FQDN, PHP_URL_HOST);
$stamp = new \DateTimeImmutable('now');

$file = BookingIcs::build(
    booking: $booking,
    summary: BookingMail::eventTitle($booking, $siteName),
    description: 'Booking reference ' . $booking['reference'] . ($booking['resource_name'] ? '. With ' . $booking['resource_name'] : ''),
    host: $host,
    stamp: $stamp,
);

\header('Content-Type: text/calendar; charset=utf-8');
\header('Content-Disposition: attachment; filename="booking-' . $booking['reference'] . '.ics"');
\header('X-Robots-Tag: noindex');
echo $file;
exit;
