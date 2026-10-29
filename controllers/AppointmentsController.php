<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumAppointments\Controllers;

use Osmium\Modules\Admin\Core\AdminController;
use Osmium\Services\OsmiumAppointments\Models\Appointment;
use Osmium\Services\OsmiumAppointments\Models\AppointmentDesk;
use Osmium\Services\OsmiumAppointments\Models\AppointmentConfig;
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
    private const WEEKDAY_NAMES = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

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
        $this->loadRules();

        $this->setView('appointments/index.phtml');
    }

    /**
     * The setup page (Services > Osmium Appointments): what can be booked, who or what is booked, and the rules
     */
    public function settings(): void
    {
        $db = $this->osmium->dataSource;

        $this->data['admin']['isAdmin'] = $this->isAdmin();
        $this->data['admin']['lists'] = require __DIR__ . '/../lists/appointments.php';
        $this->data['admin']['types'] = \array_map($this->formatType(...), (new AppointmentType($db))->listAll());
        $this->data['admin']['resources'] = \array_map($this->formatResource(...), (new AppointmentResource($db))->listAll());
        $this->loadRules();
        $this->data['admin']['steps'] = AppointmentConfig::STEPS;
        $this->data['admin']['days'] = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

        $this->setView('appointments/settings.phtml');
    }

    /** @return array<int, array{open: string, close: string}|null> the opening hours from Company > Location, by weekday */
    private function businessHours(): array
    {
        return AppointmentConfig::hoursFor(companyHours: $this->osmium->config->company->openingHours ?? null, breaks: []);
    }

    private function loadRules(): void
    {
        $companyHours = $this->osmium->config->company->openingHours ?? null;
        $this->data['admin']['settings'] = AppointmentConfig::get(companyHours: $companyHours);
        $this->data['admin']['companyHours'] = AppointmentConfig::hoursFor(companyHours: $companyHours, breaks: []);
        $this->data['admin']['settings']['notifyEmailsText'] = \implode("\n", $this->data['admin']['settings']['notifyEmails']);
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
                'confirm' => $this->desk()->confirm($id),
                'decline' => $this->desk()->decline(id: $id, message: \trim((string) ($input['message'] ?? ''))),
                'cancel' => $this->desk()->cancel(id: $id, message: \trim((string) ($input['message'] ?? ''))),
                'create' => $this->desk()->createByStaff($input),
                'reschedule' => $this->desk()->reschedule(id: $id, input: $input),
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

        $clean = (int) ($input['clean_minutes'] ?? 0);
        $cleanBad = $clean < 0 || $clean > 240;
        if ($cleanBad) throw new \InvalidArgumentException('Clean-up time must be between 0 and 240 minutes');

        $description = $this->nullIfEmpty((string) ($input['description'] ?? ''));
        $price = $this->nullIfEmpty((string) ($input['price_text'] ?? ''));
        $tooLong = \mb_strlen((string) $description) > 255 || \mb_strlen((string) $price) > 50;
        if ($tooLong) throw new \InvalidArgumentException('Description is limited to 255 characters and price to 50');

        $details = ['name' => $name, 'description' => $description, 'durationMinutes' => $minutes, 'cleanMinutes' => $clean, 'priceText' => $price, 'enabled' => !empty($input['enabled']), 'sortOrder' => \max(0, (int) ($input['sort_order'] ?? 0))];
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

    /** @return ?string JSON of the regular hours {weekday: {off} or {start, end}}, null when everything follows the business hours */
    private function validWeekly(mixed $rows): ?string
    {
        $weekly = [];
        for ($day = 1; $day <= 7; $day++) {
            $row = \is_array($rows) ? ($rows[$day] ?? $rows[(string) $day] ?? []) : [];
            if (!empty($row['off'])) {
                $weekly[$day] = ['off' => true];
                continue;
            }

            // Only ever narrower than the opening hours in Company > Location; a side left on Opening or Closing means that time
            $open = $this->businessHours()[$day] ?? null;
            $start = (string) ($row['start'] ?? '');
            $end = (string) ($row['end'] ?? '');
            $oneSideSet = ($start !== '') !== ($end !== '');
            if ($oneSideSet && $open !== null) {
                $start = $start !== '' ? $start : $open['open'];
                $end = $end !== '' ? $end : $open['close'];
            }

            $pair = AppointmentConfig::validPair(row: ['start' => $start, 'end' => $end], message: 'Regular hours need a start time earlier than the end time');
            if ($pair === null) continue;

            $dayName = self::WEEKDAY_NAMES[$day];
            if ($open === null) throw new \InvalidArgumentException("We are closed on {$dayName}s, so there are no hours to set");

            $outside = $pair['start'] < $open['open'] || $pair['end'] > $open['close'];
            if ($outside) throw new \InvalidArgumentException("Regular hours on {$dayName}s must be within the opening hours ({$open['open']} to {$open['close']})");

            $weekly[$day] = ['start' => $pair['start'], 'end' => $pair['end']];
        }

        return $weekly === [] ? null : \json_encode($weekly);
    }

    /** @return ?string JSON list of {from, to, start, end, reason}, null when there is none */
    private function validTimeOff(mixed $rows): ?string
    {
        $list = [];
        $rows = \is_array($rows) ? \array_slice($rows, 0, 100) : [];
        foreach ($rows as $row) {
            $from = \trim((string) ($row['from'] ?? ''));
            $to = \trim((string) ($row['to'] ?? '')) ?: $from;
            $empty = $from === '' && \trim((string) ($row['to'] ?? '')) === '';
            if ($empty) continue;

            $datesBad = \DateTimeImmutable::createFromFormat('!Y-m-d', $from) === false || \DateTimeImmutable::createFromFormat('!Y-m-d', $to) === false || $to < $from;
            if ($datesBad) throw new \InvalidArgumentException('Each time off needs a first day, and a last day that is not earlier');

            $pair = AppointmentConfig::validPair(row: ['start' => (string) ($row['start'] ?? ''), 'end' => (string) ($row['end'] ?? '')], message: 'Time off hours need a start time earlier than the end time');
            $reason = \mb_substr(\trim((string) ($row['reason'] ?? '')), 0, 100);
            $list[] = ['from' => $from, 'to' => $to, 'start' => $pair['start'] ?? '', 'end' => $pair['end'] ?? '', 'reason' => $reason];
        }

        return $list === [] ? null : \json_encode($list);
    }

    private function saveResource(array $input): array
    {
        $name = \trim((string) ($input['name'] ?? ''));
        $nameBad = $name === '' || \mb_strlen($name) > 100;
        if ($nameBad) throw new \InvalidArgumentException('Name is required and limited to 100 characters');

        $break = AppointmentConfig::validPair(row: ['start' => (string) ($input['break_start'] ?? ''), 'end' => (string) ($input['break_end'] ?? '')], message: 'A break needs a start time earlier than its end time');
        $weekly = $this->validWeekly($input['weekly'] ?? []);
        $timeOff = $this->validTimeOff($input['time_off'] ?? []);
        $model = new AppointmentResource($this->osmium->dataSource);
        $id = (int) ($input['id'] ?? 0);
        $enabled = !empty($input['enabled']);
        $sortOrder = \max(0, (int) ($input['sort_order'] ?? 0));

        $isNew = $id === 0;
        if (!$isNew && $model->get($id) === null) throw new \InvalidArgumentException('Not found');

        $savedId = $isNew ? $model->create(name: $name, enabled: $enabled, sortOrder: $sortOrder, breakStart: $break['start'] ?? null, breakEnd: $break['end'] ?? null, weekly: $weekly, timeOff: $timeOff) : $id;
        if (!$isNew) $model->update(id: $id, name: $name, enabled: $enabled, sortOrder: $sortOrder, breakStart: $break['start'] ?? null, breakEnd: $break['end'] ?? null, weekly: $weekly, timeOff: $timeOff);
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
            'customerName' => (string) $row['customer_name'],
            'customerEmail' => (string) $row['customer_email'],
            'customerPhone' => (string) ($row['customer_phone'] ?? ''),
            'endTime' => (new \DateTimeImmutable($row['ends_at']))->format('H:i'),
            'cleanMinutes' => (int) ($row['clean_minutes'] ?? 0),
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
            'cleanMinutes' => (int) ($row['clean_minutes'] ?? 0),
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
            'breakStart' => $row['break_start'] ?? '',
            'breakEnd' => $row['break_end'] ?? '',
            // Kept as the stored JSON text: the list prints every field as text; the page script parses them
            'weekly' => (string) ($row['weekly'] ?? ''),
            'timeOff' => (string) ($row['time_off'] ?? ''),
            'breakText' => ($row['break_start'] ?? '') !== '' ? $row['break_start'] . ' to ' . $row['break_end'] : '',
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

    private function desk(): AppointmentDesk
    {
        return new AppointmentDesk(osmium: $this->osmium, log: fn(string $action, int $id, string $name) => $this->logChange(action: $action, id: $id, name: $name));
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
