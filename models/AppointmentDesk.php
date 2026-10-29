<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumAppointments\Models;

/**
 * What the business does with an appointment: take a request from the website, confirm, decline, move or cancel one, or
 * book one for a caller. The admin screen, the website form and anything else that works the book (a test run, say)
 * all go through here, so there is one set of rules. Each method either does the job and returns its result or throws an
 * \InvalidArgumentException written for the person at the screen (or, for acceptRequest, for the customer).
 */
class AppointmentDesk
{
    /**
     * @param \Closure|null $log called with (string $action, int $id, string $name) for each change, for the admin changelog
     */
    public function __construct(private object $osmium, private ?\Closure $log = null)
    {
    }

    private function record(string $action, int $id, string $name): void
    {
        if ($this->log !== null) ($this->log)($action, $id, $name);
    }

    /**
     * A request from the website (already parsed by AppointmentRequest): checked against the opening hours, notice and
     * clashes, then saved as waiting for the business to confirm, and the customer and business are emailed.
     *
     * @param array<string, mixed> $request
     * @throws \InvalidArgumentException a message fit for the customer
     */
    public function acceptRequest(array $request): void
    {
        $db = $this->osmium->dataSource;

        $type = (new AppointmentType($db))->get($request['typeId']);
        $typeUnavailable = $type === null || !$type['enabled'];
        if ($typeUnavailable) throw new \InvalidArgumentException('Please choose what you would like to book');

        $hasResource = $request['resourceId'] !== null;
        $resource = $hasResource ? (new AppointmentResource($db))->get($request['resourceId']) : null;
        $resourceUnavailable = $hasResource && ($resource === null || !$resource['enabled']);
        if ($resourceUnavailable) throw new \InvalidArgumentException('Please choose who you would like to book with');

        $config = AppointmentConfig::get(companyHours: $this->osmium->config->company->openingHours ?? null);
        $now = new \DateTimeImmutable('now');
        $slot = AppointmentRules::slot(
            config: $config,
            date: $request['date'],
            time: $request['time'],
            durationMinutes: (int) $type['duration_minutes'],
            now: $now,
        );

        $appointments = new Appointment($db);
        $alternatives = new AppointmentAlternatives(appointments: $appointments, config: $config, now: $now);
        $people = \array_values(\array_filter((new AppointmentResource($db))->listAll(), static fn(array $row): bool => (bool) $row['enabled']));

        $needsAssigning = !$hasResource && $people !== [];
        $assigned = $needsAssigning ? $appointments->findFreeResource($people, $slot['startsAt'], $slot['endsAt'], (int) ($type['clean_minutes'] ?? 0)) : null;
        if ($needsAssigning && $assigned !== null) $request['resourceId'] = (int) $assigned['id'];

        $lane = $assigned ?? $resource;
        $taken = $needsAssigning ? $assigned === null : !$alternatives->isFree($lane, $slot['startsAt'], $slot['endsAt'], (int) ($type['clean_minutes'] ?? 0));
        if ($taken) throw new \InvalidArgumentException($alternatives->message(chosen: $resource, people: $people, date: $request['date'], time: $request['time'], minutes: (int) $type['duration_minutes'], cleanMinutes: (int) ($type['clean_minutes'] ?? 0)));

        $isDuplicate = $appointments->isDuplicate(email: $request['email'], startsAt: $slot['startsAt'], typeId: $request['typeId']);
        if ($isDuplicate) throw new \InvalidArgumentException('You have already asked for that time. We will be in touch.');

        $created = $appointments->create(request: $request, startsAt: $slot['startsAt'], endsAt: $slot['endsAt']);
        (new AppointmentNotifier($this->osmium))->requested($appointments->get($created['id']));
    }

    /** @return array{success: true, id: int} */
    public function confirm(int $id): array
    {
        $model = new Appointment($this->osmium->dataSource);
        $appointment = $this->findOpen(model: $model, id: $id, allowed: ['requested'], verb: 'confirmed');
        $this->refuseIfClash(model: $model, appointment: $appointment, resourceId: $appointment['resource_id'] !== null ? (int) $appointment['resource_id'] : null, startsAt: $appointment['starts_at'], endsAt: $appointment['ends_at'], cleanMinutes: (int) ($appointment['clean_minutes'] ?? 0));

        $model->setStatus(id: $id, status: 'confirmed', sequence: (int) $appointment['ics_sequence']);
        $this->record('Confirmed', $id, $appointment['customer_name']);
        (new AppointmentNotifier($this->osmium))->confirmed($model->get($id));

        return ['success' => true, 'id' => $id];
    }

    /** @return array{success: true, id: int} */
    public function decline(int $id, string $message): array
    {
        $model = new Appointment($this->osmium->dataSource);
        $appointment = $this->findOpen(model: $model, id: $id, allowed: ['requested'], verb: 'declined');

        $model->setStatus(id: $id, status: 'declined', sequence: (int) $appointment['ics_sequence']);
        $this->record('Declined', $id, $appointment['customer_name']);
        (new AppointmentNotifier($this->osmium))->declined(appointment: $model->get($id), message: \mb_substr($message, 0, 1000));

        return ['success' => true, 'id' => $id];
    }

    /** @return array{success: true, id: int} */
    public function cancel(int $id, string $message): array
    {
        $model = new Appointment($this->osmium->dataSource);
        $appointment = $this->findOpen(model: $model, id: $id, allowed: ['requested', 'confirmed'], verb: 'cancelled');

        $model->setStatus(id: $id, status: 'cancelled', sequence: (int) $appointment['ics_sequence'] + 1);
        $this->record('Cancelled', $id, $appointment['customer_name']);
        (new AppointmentNotifier($this->osmium))->cancelled(appointment: $model->get($id), message: \mb_substr($message, 0, 1000));

        return ['success' => true, 'id' => $id];
    }

    /**
     * An appointment made for a customer who rang or called in: confirmed at once (and emailed, when there is an
     * address). Opening hours, breaks and clashes still apply; the notice and how-far-ahead limits are for the website.
     *
     * @param array<string, mixed> $input
     * @return array{success: true, id: int}
     */
    public function createByStaff(array $input): array
    {
        $db = $this->osmium->dataSource;

        $name = \trim((string) ($input['name'] ?? ''));
        $nameBad = $name === '' || \mb_strlen($name) > 100;
        if ($nameBad) throw new \InvalidArgumentException('Enter the customer\'s name (up to 100 characters)');

        $email = \trim((string) ($input['email'] ?? ''));
        $phone = \trim((string) ($input['phone'] ?? ''));
        $emailBad = $email !== '' && (\filter_var($email, FILTER_VALIDATE_EMAIL) === false || \strlen($email) > 150);
        if ($emailBad) throw new \InvalidArgumentException('That email address is not valid');

        $noContact = $email === '' && $phone === '';
        if ($noContact) throw new \InvalidArgumentException('Enter a phone number or an email address');
        if (\strlen($phone) > 30) throw new \InvalidArgumentException('Phone number is limited to 30 characters');

        $type = (new AppointmentType($db))->get((int) ($input['type_id'] ?? 0));
        if ($type === null) throw new \InvalidArgumentException('Choose what is being booked');

        $resourceId = (int) ($input['resource_id'] ?? 0);
        $resource = $resourceId > 0 ? (new AppointmentResource($db))->get($resourceId) : null;
        $resourceMissing = $resourceId > 0 && $resource === null;
        if ($resourceMissing) throw new \InvalidArgumentException('Choose who or what is booked');

        $date = \trim((string) ($input['date'] ?? ''));
        $time = \trim((string) ($input['time'] ?? ''));
        $config = AppointmentConfig::get(companyHours: $this->osmium->config->company->openingHours ?? null);
        $now = new \DateTimeImmutable('now');
        $slot = AppointmentRules::slot(config: ['minNoticeHours' => 0, 'maxDaysAhead' => 730] + $config, date: $date, time: $time, durationMinutes: (int) $type['duration_minutes'], now: $now);

        $model = new Appointment($db);
        $people = \array_values(\array_filter((new AppointmentResource($db))->listAll(), static fn(array $row): bool => (bool) $row['enabled']));
        $alternatives = new AppointmentAlternatives(appointments: $model, config: $config, now: $now);

        $needsAssigning = $resource === null && $people !== [];
        $assigned = $needsAssigning ? $model->findFreeResource($people, $slot['startsAt'], $slot['endsAt'], (int) $type['clean_minutes']) : $resource;
        $taken = $needsAssigning ? $assigned === null : !$alternatives->isFree($assigned, $slot['startsAt'], $slot['endsAt'], (int) $type['clean_minutes']);
        if ($taken) throw new \InvalidArgumentException($alternatives->message(chosen: $resource, people: $people, date: $date, time: $time, minutes: (int) $type['duration_minutes'], cleanMinutes: (int) $type['clean_minutes']));

        $notes = \trim((string) ($input['notes'] ?? ''));
        $created = $model->create(
            request: ['typeId' => (int) $type['id'], 'resourceId' => $assigned !== null ? (int) $assigned['id'] : null, 'name' => $name, 'email' => $email, 'phone' => $phone !== '' ? $phone : null, 'notes' => $notes !== '' ? $notes : null],
            startsAt: $slot['startsAt'],
            endsAt: $slot['endsAt'],
        );
        $model->setStatus(id: $created['id'], status: 'confirmed', sequence: 0);
        $this->record('Created by staff', $created['id'], $name);
        (new AppointmentNotifier($this->osmium))->confirmed($model->get($created['id']));

        return ['success' => true, 'id' => $created['id']];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{success: true, id: int}
     */
    public function reschedule(int $id, array $input): array
    {
        $model = new Appointment($this->osmium->dataSource);
        $appointment = $model->get($id);
        $missing = $appointment === null || $appointment['deleted_at'] !== null;
        if ($missing) throw new \InvalidArgumentException('Appointment not found');

        $typeId = (int) ($input['type_id'] ?? 0);
        $type = (new AppointmentType($this->osmium->dataSource))->get($typeId);
        if ($type === null) throw new \InvalidArgumentException('Choose what is being booked');

        $resourceId = (int) ($input['resource_id'] ?? 0);
        $hasResource = $resourceId > 0;
        $resourceMissing = $hasResource && (new AppointmentResource($this->osmium->dataSource))->get($resourceId) === null;
        if ($resourceMissing) throw new \InvalidArgumentException('Choose who or what is booked');

        $start = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', \trim((string) ($input['date'] ?? '')) . ' ' . \trim((string) ($input['time'] ?? '')));
        $startBad = $start === false;
        if ($startBad) throw new \InvalidArgumentException('Enter a real date and time');

        $startsAt = $start->format('Y-m-d H:i:s');
        $endsAt = $start->modify('+' . (int) $type['duration_minutes'] . ' minutes')->format('Y-m-d H:i:s');
        $resourceValue = $hasResource ? $resourceId : null;

        $timeChanged = $startsAt !== $appointment['starts_at'] || $endsAt !== $appointment['ends_at'] || $resourceValue !== ($appointment['resource_id'] !== null ? (int) $appointment['resource_id'] : null);
        if ($timeChanged) $this->refuseOutsideOpeningHours(start: $start, minutes: (int) $type['duration_minutes']);

        $isConfirmed = $appointment['status'] === 'confirmed';
        if ($isConfirmed) $this->refuseIfClash(model: $model, appointment: $appointment, resourceId: $resourceValue, startsAt: $startsAt, endsAt: $endsAt, cleanMinutes: (int) $type['clean_minutes']);

        $sequence = (int) $appointment['ics_sequence'] + ($isConfirmed && $timeChanged ? 1 : 0);
        $notes = \trim((string) ($input['admin_notes'] ?? ''));

        $model->reschedule(id: $id, typeId: $typeId, resourceId: $resourceValue, startsAt: $startsAt, endsAt: $endsAt, adminNotes: $notes !== '' ? $notes : null, sequence: $sequence);
        $this->record('Updated', $id, $appointment['customer_name']);

        $tellCustomer = $isConfirmed && $timeChanged;
        if ($tellCustomer) (new AppointmentNotifier($this->osmium))->changed($model->get($id));

        return ['success' => true, 'id' => $id];
    }

    private function refuseOutsideOpeningHours(\DateTimeImmutable $start, int $minutes): void
    {
        AppointmentRules::staffSlot(config: AppointmentConfig::get(companyHours: $this->osmium->config->company->openingHours ?? null), start: $start, durationMinutes: $minutes);
    }

    /** @return array<string, mixed> */
    private function findOpen(Appointment $model, int $id, array $allowed, string $verb): array
    {
        $appointment = $model->get($id);
        $missing = $appointment === null || $appointment['deleted_at'] !== null;
        if ($missing) throw new \InvalidArgumentException('Appointment not found');

        $wrongState = !\in_array($appointment['status'], $allowed, true);
        if ($wrongState) throw new \InvalidArgumentException('This appointment is ' . $appointment['status'] . ' and cannot be ' . $verb);

        return $appointment;
    }

    private function refuseIfClash(Appointment $model, array $appointment, ?int $resourceId, string $startsAt, string $endsAt, int $cleanMinutes = 0): void
    {
        $person = $resourceId !== null ? (new AppointmentResource($this->osmium->dataSource))->get($resourceId) : null;
        $reason = AppointmentRules::unavailableReason($person, $startsAt, $endsAt);
        if ($reason !== null) throw new \InvalidArgumentException($reason . '. Change the time or person first.');

        $clash = $model->findOverlap(excludeId: (int) $appointment['id'], resourceId: $resourceId, startsAt: $startsAt, endsAt: $endsAt, cleanMinutes: $cleanMinutes);
        if ($clash === null) return;

        $when = (new \DateTimeImmutable($clash['starts_at']))->format('D j M, H:i') . ' to ' . (new \DateTimeImmutable($clash['ends_at']))->format('H:i');
        throw new \InvalidArgumentException('That clashes with a confirmed appointment for ' . $clash['customer_name'] . ' (' . $when . '). Change the time first.');
    }
}
