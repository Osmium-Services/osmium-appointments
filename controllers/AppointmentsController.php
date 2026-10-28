<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumAppointments\Controllers;

use Osmium\Modules\Admin\Core\AdminController;
use Osmium\Services\OsmiumAppointments\Models\Appointment;
use Osmium\Services\OsmiumAppointments\Models\AppointmentConfig;
use Osmium\Services\OsmiumAppointments\Models\AppointmentNotifier;
use Osmium\Services\OsmiumAppointments\Models\AppointmentResource;
use Osmium\Services\OsmiumAppointments\Models\AppointmentType;

/**
 * Appointments controller - the requests, what can be booked, who or what is booked, and the opening-hours settings.
 *
 * Routes:
 *   - index()  → /admin/appointments/
 *   - action() → /admin/appointments/action/   (AJAX)
 */
class AppointmentsController extends AdminController
{
    private const RECORD_TYPE = 'appointment';

    // =========================================================================
    // Public Actions
    // =========================================================================

    public function index(): void
    {
        $db = $this->osmium->dataSource;
        $appointments = new Appointment($db);

        $this->data['admin']['isAdmin'] = $this->isAdmin();
        $this->data['admin']['lists'] = require __DIR__ . '/../lists/appointments.php';
        $this->data['admin']['appointments'] = \array_map($this->formatAppointment(...), $appointments->listAll());
        $this->data['admin']['deletedAppointments'] = \array_map($this->formatDeleted(...), $appointments->listDeleted());
        $this->data['admin']['types'] = \array_map($this->formatType(...), (new AppointmentType($db))->listAll());
        $this->data['admin']['resources'] = \array_map($this->formatResource(...), (new AppointmentResource($db))->listAll());
        $companyHours = $this->osmium->config->company->openingHours ?? null;
        $this->data['admin']['settings'] = AppointmentConfig::get(companyHours: $companyHours);
        $this->data['admin']['companyHours'] = AppointmentConfig::hoursFor(companyHours: $companyHours, breaks: []);
        $this->data['admin']['settings']['notifyEmailsText'] = \implode("\n", $this->data['admin']['settings']['notifyEmails']);
        $this->data['admin']['steps'] = AppointmentConfig::STEPS;
        $this->data['admin']['days'] = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

        $this->setView('appointments/index.phtml');
    }

    public function action()
    {
        \header('Content-Type: application/json');

        $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
        if (!$isPost) $this->admin->jsonError('Method not allowed');

        $input = $this->admin->auth->getJsonInput();
        $action = $input['action'] ?? '';
        $id = (int) ($input['id'] ?? 0);

        $csrfValid = $this->admin->auth->validateCsrfJson($input);
        if (!$csrfValid) $this->admin->jsonError('Invalid request token. Please refresh and try again.');

        $needsId = \in_array($action, ['confirm', 'decline', 'cancel', 'reschedule', 'delete', 'restore', 'permanently_delete', 'delete_type', 'delete_resource'], true);
        $missingParams = !$action || ($needsId && !$id);
        if ($missingParams) $this->admin->jsonError('Missing parameters');

        try {
            $result = match ($action) {
                'confirm' => $this->confirm($id),
                'decline' => $this->decline($id),
                'cancel' => $this->cancel($id),
                'reschedule' => $this->reschedule(id: $id, input: $input),
                'delete' => $this->deleteAppointment($id),
                'restore' => $this->restoreAppointment($id),
                'permanently_delete' => $this->permanentlyDelete($id),
                'purge_deleted' => $this->purgeDeleted(),
                'save_type' => $this->saveType($input),
                'delete_type' => $this->deleteType($id),
                'save_resource' => $this->saveResource($input),
                'delete_resource' => $this->deleteResource($id),
                'save_settings' => $this->saveSettings($input),
                default => throw new \InvalidArgumentException('Unknown action'),
            };

            $this->admin->jsonSuccess($result);
        } catch (\Exception $e) {
            $this->admin->jsonError($e->getMessage());
        }
    }

    // =========================================================================
    // Appointments
    // =========================================================================

    private function confirm(int $id): array
    {
        $model = new Appointment($this->osmium->dataSource);
        $appointment = $this->findOpen(model: $model, id: $id, allowed: ['requested'], verb: 'confirmed');
        $this->refuseIfClash(model: $model, appointment: $appointment, resourceId: $appointment['resource_id'] !== null ? (int) $appointment['resource_id'] : null, startsAt: $appointment['starts_at'], endsAt: $appointment['ends_at']);

        $model->setStatus(id: $id, status: 'confirmed', sequence: (int) $appointment['ics_sequence']);
        $this->logChange(action: 'Confirmed', id: $id, name: $appointment['customer_name']);
        (new AppointmentNotifier($this->osmium))->confirmed($model->get($id));

        return ['success' => true, 'id' => $id];
    }

    private function decline(int $id): array
    {
        $model = new Appointment($this->osmium->dataSource);
        $appointment = $this->findOpen(model: $model, id: $id, allowed: ['requested'], verb: 'declined');

        $model->setStatus(id: $id, status: 'declined', sequence: (int) $appointment['ics_sequence']);
        $this->logChange(action: 'Declined', id: $id, name: $appointment['customer_name']);
        (new AppointmentNotifier($this->osmium))->declined($model->get($id));

        return ['success' => true, 'id' => $id];
    }

    private function cancel(int $id): array
    {
        $model = new Appointment($this->osmium->dataSource);
        $appointment = $this->findOpen(model: $model, id: $id, allowed: ['requested', 'confirmed'], verb: 'cancelled');

        $model->setStatus(id: $id, status: 'cancelled', sequence: (int) $appointment['ics_sequence'] + 1);
        $this->logChange(action: 'Cancelled', id: $id, name: $appointment['customer_name']);
        (new AppointmentNotifier($this->osmium))->cancelled($model->get($id));

        return ['success' => true, 'id' => $id];
    }

    private function reschedule(int $id, array $input): array
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

        $isConfirmed = $appointment['status'] === 'confirmed';
        if ($isConfirmed) $this->refuseIfClash(model: $model, appointment: $appointment, resourceId: $resourceValue, startsAt: $startsAt, endsAt: $endsAt);

        $timeChanged = $startsAt !== $appointment['starts_at'] || $endsAt !== $appointment['ends_at'] || $resourceValue !== ($appointment['resource_id'] !== null ? (int) $appointment['resource_id'] : null);
        $sequence = (int) $appointment['ics_sequence'] + ($isConfirmed && $timeChanged ? 1 : 0);
        $notes = \trim((string) ($input['admin_notes'] ?? ''));

        $model->reschedule(id: $id, typeId: $typeId, resourceId: $resourceValue, startsAt: $startsAt, endsAt: $endsAt, adminNotes: $notes !== '' ? $notes : null, sequence: $sequence);
        $this->logChange(action: 'Updated', id: $id, name: $appointment['customer_name']);

        $tellCustomer = $isConfirmed && $timeChanged;
        if ($tellCustomer) (new AppointmentNotifier($this->osmium))->changed($model->get($id));

        return ['success' => true, 'id' => $id];
    }

    private function deleteAppointment(int $id): array
    {
        $model = new Appointment($this->osmium->dataSource);
        $appointment = $model->get($id);
        $missing = $appointment === null || $appointment['deleted_at'] !== null;
        if ($missing) throw new \InvalidArgumentException('Appointment not found');

        $model->softDelete($id);
        $this->logChange(action: 'Deleted', id: $id, name: $appointment['customer_name']);

        return ['success' => true, 'id' => $id];
    }

    private function restoreAppointment(int $id): array
    {
        $model = new Appointment($this->osmium->dataSource);
        $appointment = $model->get($id);
        if (!$appointment) throw new \InvalidArgumentException('Appointment not found');

        $model->restore($id);
        $this->logChange(action: 'Restored', id: $id, name: $appointment['customer_name']);

        return ['success' => true, 'id' => $id];
    }

    private function permanentlyDelete(int $id): array
    {
        $notAdmin = !$this->isAdmin();
        if ($notAdmin) throw new \Exception('Unauthorized');

        $model = new Appointment($this->osmium->dataSource);
        $appointment = $model->get($id);
        if (!$appointment) throw new \InvalidArgumentException('Appointment not found');

        $this->logChange(action: 'Permanently deleted', id: $id, name: $appointment['customer_name']);
        $model->hardDelete($id);

        return ['success' => true, 'id' => $id];
    }

    private function purgeDeleted(): array
    {
        $notAdmin = !$this->isAdmin();
        if ($notAdmin) throw new \Exception('Unauthorized');

        $count = (new Appointment($this->osmium->dataSource))->purgeDeleted();
        $this->admin->model->changelog->log(description: "Purged {$count} deleted appointment(s)", recordType: self::RECORD_TYPE, recordId: 0);

        return ['success' => true, 'count' => $count];
    }

    /**
     * @param list<string> $allowed
     */
    private function findOpen(Appointment $model, int $id, array $allowed, string $verb): array
    {
        $appointment = $model->get($id);
        $missing = $appointment === null || $appointment['deleted_at'] !== null;
        if ($missing) throw new \InvalidArgumentException('Appointment not found');

        $wrongState = !\in_array($appointment['status'], $allowed, true);
        if ($wrongState) throw new \InvalidArgumentException('This appointment is ' . $appointment['status'] . ' and cannot be ' . $verb);

        return $appointment;
    }

    private function refuseIfClash(Appointment $model, array $appointment, ?int $resourceId, string $startsAt, string $endsAt): void
    {
        $clash = $model->findOverlap(excludeId: (int) $appointment['id'], resourceId: $resourceId, startsAt: $startsAt, endsAt: $endsAt);
        if ($clash === null) return;

        $when = (new \DateTimeImmutable($clash['starts_at']))->format('D j M, H:i') . ' to ' . (new \DateTimeImmutable($clash['ends_at']))->format('H:i');
        throw new \InvalidArgumentException('That clashes with a confirmed appointment for ' . $clash['customer_name'] . ' (' . $when . '). Change the time first.');
    }

    // =========================================================================
    // Types, resources and settings
    // =========================================================================

    private function saveType(array $input): array
    {
        $name = \trim((string) ($input['name'] ?? ''));
        $nameBad = $name === '' || \mb_strlen($name) > 100;
        if ($nameBad) throw new \InvalidArgumentException('Name is required and limited to 100 characters');

        $minutes = (int) ($input['duration_minutes'] ?? 0);
        $minutesBad = $minutes < 5 || $minutes > 720;
        if ($minutesBad) throw new \InvalidArgumentException('Length must be between 5 and 720 minutes');

        $description = $this->nullIfEmpty((string) ($input['description'] ?? ''));
        $price = $this->nullIfEmpty((string) ($input['price_text'] ?? ''));
        $tooLong = \mb_strlen((string) $description) > 255 || \mb_strlen((string) $price) > 50;
        if ($tooLong) throw new \InvalidArgumentException('Description is limited to 255 characters and price to 50');

        $details = ['name' => $name, 'description' => $description, 'durationMinutes' => $minutes, 'priceText' => $price, 'enabled' => !empty($input['enabled']), 'sortOrder' => \max(0, (int) ($input['sort_order'] ?? 0))];
        $model = new AppointmentType($this->osmium->dataSource);
        $id = (int) ($input['id'] ?? 0);

        $isNew = $id === 0;
        if (!$isNew && $model->get($id) === null) throw new \InvalidArgumentException('Not found');

        $savedId = $isNew ? $model->create($details) : $id;
        if (!$isNew) $model->update(id: $id, details: $details);
        $this->admin->model->changelog->log(description: ($isNew ? 'Created' : 'Updated') . ' appointment type: ' . $name, recordType: 'appointment_type', recordId: $savedId);

        return ['success' => true, 'id' => $savedId];
    }

    private function deleteType(int $id): array
    {
        $model = new AppointmentType($this->osmium->dataSource);
        $type = \array_values(\array_filter($model->listAll(), static fn(array $row): bool => (int) $row['id'] === $id))[0] ?? null;
        if ($type === null) throw new \InvalidArgumentException('Not found');

        $inUse = (int) $type['appointment_count'] > 0;
        if ($inUse) throw new \InvalidArgumentException('Appointments use this, so it cannot be deleted. Turn it off instead.');

        $model->delete($id);
        $this->admin->model->changelog->log(description: 'Deleted appointment type: ' . $type['name'], recordType: 'appointment_type', recordId: $id);

        return ['success' => true, 'id' => $id];
    }

    private function saveResource(array $input): array
    {
        $name = \trim((string) ($input['name'] ?? ''));
        $nameBad = $name === '' || \mb_strlen($name) > 100;
        if ($nameBad) throw new \InvalidArgumentException('Name is required and limited to 100 characters');

        $model = new AppointmentResource($this->osmium->dataSource);
        $id = (int) ($input['id'] ?? 0);
        $enabled = !empty($input['enabled']);
        $sortOrder = \max(0, (int) ($input['sort_order'] ?? 0));

        $isNew = $id === 0;
        if (!$isNew && $model->get($id) === null) throw new \InvalidArgumentException('Not found');

        $savedId = $isNew ? $model->create(name: $name, enabled: $enabled, sortOrder: $sortOrder) : $id;
        if (!$isNew) $model->update(id: $id, name: $name, enabled: $enabled, sortOrder: $sortOrder);
        $this->admin->model->changelog->log(description: ($isNew ? 'Created' : 'Updated') . ' appointment resource: ' . $name, recordType: 'appointment_resource', recordId: $savedId);

        return ['success' => true, 'id' => $savedId];
    }

    private function deleteResource(int $id): array
    {
        $model = new AppointmentResource($this->osmium->dataSource);
        $resource = \array_values(\array_filter($model->listAll(), static fn(array $row): bool => (int) $row['id'] === $id))[0] ?? null;
        if ($resource === null) throw new \InvalidArgumentException('Not found');

        $inUse = (int) $resource['appointment_count'] > 0;
        if ($inUse) throw new \InvalidArgumentException('Appointments use this, so it cannot be deleted. Turn it off instead.');

        $model->delete($id);
        $this->admin->model->changelog->log(description: 'Deleted appointment resource: ' . $resource['name'], recordType: 'appointment_resource', recordId: $id);

        return ['success' => true, 'id' => $id];
    }

    private function saveSettings(array $input): array
    {
        $breaks = [];
        $windows = [];
        for ($day = 1; $day <= 7; $day++) {
            $windows[$day] = [
                'start' => (string) ($input["window_start_{$day}"] ?? ''),
                'end' => (string) ($input["window_end_{$day}"] ?? ''),
            ];
            $breaks[$day] = [
                'start' => (string) ($input["break_start_{$day}"] ?? ''),
                'end' => (string) ($input["break_end_{$day}"] ?? ''),
            ];
        }

        AppointmentConfig::save([
            'windows' => $windows,
            'breaks' => $breaks,
            'stepMinutes' => $input['step_minutes'] ?? 0,
            'minNoticeHours' => $input['min_notice_hours'] ?? -1,
            'maxDaysAhead' => $input['max_days_ahead'] ?? 0,
            'notifyEmails' => (string) ($input['notify_emails'] ?? ''),
        ]);
        $this->admin->model->changelog->log(description: 'Updated appointment settings', recordType: 'settings', recordId: 0);

        return ['success' => true];
    }

    // =========================================================================
    // Formatting
    // =========================================================================

    private function formatAppointment(array $row): array
    {
        $start = new \DateTimeImmutable($row['starts_at']);

        return [
            'id' => (int) $row['id'],
            'reference' => $row['reference'],
            'status' => $row['status'],
            'statusBadge' => $this->statusBadge($row['status']),
            'whenText' => $start->format('D j M Y, H:i'),
            'customerHtml' => '<strong>' . \htmlspecialchars((string) $row['customer_name']) . '</strong><br><small>' . \htmlspecialchars((string) $row['customer_email']) . ($row['customer_phone'] ? ' &middot; ' . \htmlspecialchars((string) $row['customer_phone']) : '') . '</small>',
            'typeName' => $row['type_name'] ?? '',
            'resourceName' => $row['resource_name'] ?? '',
            'typeId' => (int) $row['type_id'],
            'resourceId' => $row['resource_id'] !== null ? (int) $row['resource_id'] : '',
            'date' => $start->format('Y-m-d'),
            'time' => $start->format('H:i'),
            'customerNotes' => $row['customer_notes'] ?? '',
            'adminNotes' => $row['admin_notes'] ?? '',
        ];
    }

    private function formatDeleted(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['customer_name'] . ' (' . $row['reference'] . ')',
            'deletedDate' => \date('d M Y', \strtotime((string) $row['deleted_at'])),
            'changed_by' => $row['changed_by'] ?? null,
            'changed_by_avatar' => $row['changed_by_avatar'] ?? null,
        ];
    }

    private function formatType(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'description' => $row['description'] ?? '',
            'minutes' => (int) $row['duration_minutes'],
            'priceText' => $row['price_text'] ?? '',
            'enabled' => (bool) $row['enabled'],
            'sortOrder' => (int) $row['sort_order'],
            'appointmentCount' => (int) $row['appointment_count'],
            'enabledBadge' => $row['enabled'] ? '<span class="badge bg-label-success">On</span>' : '<span class="badge bg-label-secondary">Off</span>',
        ];
    }

    private function formatResource(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'enabled' => (bool) $row['enabled'],
            'sortOrder' => (int) $row['sort_order'],
            'appointmentCount' => (int) $row['appointment_count'],
            'enabledBadge' => $row['enabled'] ? '<span class="badge bg-label-success">On</span>' : '<span class="badge bg-label-secondary">Off</span>',
        ];
    }

    private function statusBadge(string $status): string
    {
        $colour = match ($status) {
            'requested' => 'warning',
            'confirmed' => 'success',
            default => 'secondary',
        };

        return '<span class="badge bg-label-' . $colour . '">' . \ucfirst($status) . '</span>';
    }

    private function nullIfEmpty(string $value): ?string
    {
        $trimmed = \trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function logChange(string $action, int $id, string $name): void
    {
        $this->admin->model->changelog->log(
            description: $action . ': ' . $name,
            recordType: self::RECORD_TYPE,
            recordId: $id,
        );
    }
}
