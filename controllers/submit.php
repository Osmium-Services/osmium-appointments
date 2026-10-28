<?php

use Osmium\Core\Library\ServiceHooks;
use Osmium\Core\Library\ServiceLoader;
use Osmium\Core\Models\ServiceModel;
use Osmium\Services\OsmiumBookings\Models\Booking;
use Osmium\Services\OsmiumBookings\Models\BookingConfig;
use Osmium\Services\OsmiumBookings\Models\BookingNotifier;
use Osmium\Services\OsmiumBookings\Models\BookingRequest;
use Osmium\Services\OsmiumBookings\Models\BookingResource;
use Osmium\Services\OsmiumBookings\Models\BookingRules;
use Osmium\Services\OsmiumBookings\Models\BookingType;
use Osmium\Services\OsmiumBookings\Models\Bookings;

/**
 * Booking request endpoint (POST /api/bookings). Fields: type_id, resource_id (optional), date (Y-m-d), time (H:i),
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
$notice = fn(string $status, string $message) => Bookings::rememberNotice(session: $_SESSION, status: $status, message: $message);

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
    ->collect('form.verify', ['form' => 'booking', 'post' => $_POST, 'dataSource' => $this->osmium->dataSource]);
$protectionFailed = \in_array(false, $protectionAnswers, true);

$looksLikeABot = $botDetected || !$tokenIsValid || $submittedTooFast || $protectionFailed;
if ($looksLikeABot) {
    $notice('failed', BookingRequest::TECHNICAL_PROBLEM); // Never say which check failed
    $this->osmium->header->redirect(targetURL: $back, statusCode: 302);
}

try {
    $request = BookingRequest::parse($_POST);

    $type = (new BookingType($this->osmium->dataSource))->get($request['typeId']);
    $typeUnavailable = $type === null || !$type['enabled'];
    if ($typeUnavailable) throw new \InvalidArgumentException('Please choose what you would like to book');

    $hasResource = $request['resourceId'] !== null;
    $resource = $hasResource ? (new BookingResource($this->osmium->dataSource))->get($request['resourceId']) : null;
    $resourceUnavailable = $hasResource && ($resource === null || !$resource['enabled']);
    if ($resourceUnavailable) throw new \InvalidArgumentException('Please choose who you would like to book with');

    $slot = BookingRules::slot(
        config: BookingConfig::get(companyHours: $this->osmium->config->company->openingHours ?? null),
        date: $request['date'],
        time: $request['time'],
        durationMinutes: (int) $type['duration_minutes'],
        now: new \DateTimeImmutable('now'),
    );

    $bookings = new Booking($this->osmium->dataSource);
    $isDuplicate = $bookings->isDuplicate(email: $request['email'], startsAt: $slot['startsAt'], typeId: $request['typeId']);
    if ($isDuplicate) throw new \InvalidArgumentException('You have already asked for that time. We will be in touch.');

    $created = $bookings->create(request: $request, startsAt: $slot['startsAt'], endsAt: $slot['endsAt']);
    (new BookingNotifier($this->osmium))->requested($bookings->get($created['id']));

    $notice('sent', 'Thanks, your request has been sent. We will confirm it by email.');
} catch (\InvalidArgumentException $e) {
    $notice('failed', $e->getMessage()); // These messages are written for the customer
} catch (\Throwable $e) {
    \error_log('Bookings: request failed: ' . $e->getMessage());
    $notice('failed', BookingRequest::TECHNICAL_PROBLEM);
}

$this->osmium->header->redirect(targetURL: $back, statusCode: 302);
