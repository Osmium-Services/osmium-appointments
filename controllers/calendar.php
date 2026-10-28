<?php

use Osmium\Services\OsmiumAppointments\Models\Appointment;
use Osmium\Services\OsmiumAppointments\Models\AppointmentIcs;
use Osmium\Services\OsmiumAppointments\Models\AppointmentMail;

/**
 * Calendar file for an appointment (GET /api/appointments-calendar/?ref=REFERENCE). The reference is the key: ten characters
 * chosen at random, only in the customer's emails. The file holds the event, the time and the reference, never the
 * customer's details. A requested or declined appointment is not an event, so it answers 404.
 */

$reference = \preg_replace('/[^A-Za-z0-9]/', '', (string) ($_GET['ref'] ?? ''));
$appointment = $reference !== '' ? (new Appointment($this->osmium->dataSource))->getByReference($reference) : null;

$isEvent = $appointment !== null && \in_array($appointment['status'], ['confirmed', 'cancelled'], true);
if (!$isEvent) {
    \http_response_code(404);
    echo 'Not found';
    exit;
}

$siteName = (string) $this->osmium->config->site->name;
$host = (string) \parse_url($this->osmium->config->site->FQDN, PHP_URL_HOST);
$stamp = new \DateTimeImmutable('now');

$file = AppointmentIcs::build(
    appointment: $appointment,
    summary: AppointmentMail::eventTitle($appointment, $siteName),
    description: 'Appointment reference ' . $appointment['reference'] . ($appointment['resource_name'] ? '. With ' . $appointment['resource_name'] : ''),
    host: $host,
    stamp: $stamp,
);

\header('Content-Type: text/calendar; charset=utf-8');
\header('Content-Disposition: attachment; filename="appointment-' . $appointment['reference'] . '.ics"');
\header('X-Robots-Tag: noindex');
echo $file;
exit;
