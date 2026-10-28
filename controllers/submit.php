<?php

use Osmium\Core\Library\ServiceHooks;
use Osmium\Core\Library\ServiceLoader;
use Osmium\Core\Models\ServiceModel;
use Osmium\Services\OsmiumAppointments\Models\Appointment;
use Osmium\Services\OsmiumAppointments\Models\AppointmentConfig;
use Osmium\Services\OsmiumAppointments\Models\AppointmentNotifier;
use Osmium\Services\OsmiumAppointments\Models\AppointmentRequest;
use Osmium\Services\OsmiumAppointments\Models\AppointmentResource;
use Osmium\Services\OsmiumAppointments\Models\AppointmentRules;
use Osmium\Services\OsmiumAppointments\Models\AppointmentType;
use Osmium\Services\OsmiumAppointments\Models\Appointments;

/**
 * Appointment request endpoint (POST /api/appointments). Fields: type_id, resource_id (optional), date (Y-m-d), time (H:i),
 * name, email, phone (optional), notes (optional), plus the form's token and the zip honeypot, and the answer of any
 * bot protection installed (form.verify). The visitor is always sent back to the page they came from, with a
 * one-time notice for the theme to show.
 */

$sg = $this->osmium->superGlobal;

$requestMethod = $sg->get(type: 'SERVER', name: 'REQUEST_METHOD');
$notPost = $requestMethod !== 'POST';
if ($notPost) {
    $this->osmium->header->redirect(targetURL: '/', statusCode: 302);
}

$referrer = $_SERVER['HTTP_REFERER'] ?? '/';
$back = \parse_url(url: $referrer, component: PHP_URL_PATH) ?: '/'; // Only the path: never send a visitor to another site
$notice = fn(string $status, string $message) => Appointments::rememberNotice(session: $_SESSION, status: $status, message: $message);

$honeyPot = $sg->get(type: 'POST', name: 'zip');
$botDetected = !empty($honeyPot);

$submittedToken = $sg->get(type: 'POST', name: 'token');
$sessionToken = $sg->get(type: 'SESSION', name: 'token');
$tokenIsValid = !empty($submittedToken)
    && !empty($sessionToken)
    && \hash_equals(known_string: $sessionToken, user_string: $submittedToken);

$tokenIssuedAt = $sg->get(type: 'SESSION', name: 'token_issued_at');
$secondsSinceTokenIssued = $tokenIssuedAt ? (\time() - (int) $tokenIssuedAt) : 0;
$submittedTooFast = $secondsSinceTokenIssued < 4;

unset($_SESSION['token'], $_SESSION['token_issued_at']); // One token, one submission

// Any installed bot protection answers the form.verify hook; this service never names one
$protectionAnswers = (new ServiceHooks(new ServiceLoader(new ServiceModel($this->osmium->dataSource))))
    ->collect('form.verify', ['form' => 'appointment', 'post' => $_POST, 'dataSource' => $this->osmium->dataSource]);
$protectionFailed = \in_array(false, $protectionAnswers, true);

$looksLikeABot = $botDetected || !$tokenIsValid || $submittedTooFast || $protectionFailed;
if ($looksLikeABot) {
    $notice('failed', AppointmentRequest::TECHNICAL_PROBLEM); // Never say which check failed
    $this->osmium->header->redirect(targetURL: $back, statusCode: 302);
}

try {
    $request = AppointmentRequest::parse($_POST);

    $type = (new AppointmentType($this->osmium->dataSource))->get($request['typeId']);
    $typeUnavailable = $type === null || !$type['enabled'];
    if ($typeUnavailable) throw new \InvalidArgumentException('Please choose what you would like to book');

    $hasResource = $request['resourceId'] !== null;
    $resource = $hasResource ? (new AppointmentResource($this->osmium->dataSource))->get($request['resourceId']) : null;
    $resourceUnavailable = $hasResource && ($resource === null || !$resource['enabled']);
    if ($resourceUnavailable) throw new \InvalidArgumentException('Please choose who you would like to book with');

    $slot = AppointmentRules::slot(
        config: AppointmentConfig::get(companyHours: $this->osmium->config->company->openingHours ?? null),
        date: $request['date'],
        time: $request['time'],
        durationMinutes: (int) $type['duration_minutes'],
        now: new \DateTimeImmutable('now'),
    );

    $appointments = new Appointment($this->osmium->dataSource);
    $isDuplicate = $appointments->isDuplicate(email: $request['email'], startsAt: $slot['startsAt'], typeId: $request['typeId']);
    if ($isDuplicate) throw new \InvalidArgumentException('You have already asked for that time. We will be in touch.');

    $created = $appointments->create(request: $request, startsAt: $slot['startsAt'], endsAt: $slot['endsAt']);
    (new AppointmentNotifier($this->osmium))->requested($appointments->get($created['id']));

    $notice('sent', 'Thanks, your request has been sent. We will confirm it by email.');
} catch (\InvalidArgumentException $e) {
    $notice('failed', $e->getMessage()); // These messages are written for the customer
} catch (\Throwable $e) {
    \error_log('Appointments: request failed: ' . $e->getMessage());
    $notice('failed', AppointmentRequest::TECHNICAL_PROBLEM);
}

$this->osmium->header->redirect(targetURL: $back, statusCode: 302);
