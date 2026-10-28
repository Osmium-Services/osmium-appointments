<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumBookings\Controllers;

use Osmium\Modules\Admin\Core\AdminController;
use Osmium\Services\OsmiumBookings\Models\Booking;
use Osmium\Services\OsmiumBookings\Models\BookingConfig;
use Osmium\Services\OsmiumBookings\Models\BookingNotifier;
use Osmium\Services\OsmiumBookings\Models\BookingResource;
use Osmium\Services\OsmiumBookings\Models\BookingType;

/**
 * Bookings controller - the requests, what can be booked, who or what is booked, and the opening-hours settings.
 *
 * Routes:
 *   - index()  → /admin/bookings/
 *   - action() → /admin/bookings/action/   (AJAX)
 */
class BookingsController extends AdminController
{
    private const RECORD_TYPE = 'booking';

    // =========================================================================
    // Public Actions
    // =========================================================================

    public function index(): void
    {
        $db = $this->osmium->dataSource;
        $bookings = new Booking($db);

        $this->data['admin']['isAdmin'] = $this->isAdmin();
        $this->data['admin']['lists'] = require __DIR__ . '/../lists/bookings.php';
        $this->data['admin']['bookings'] = \array_map($this->formatBooking(...), $bookings->listAll());
        $this->data['admin']['deletedBookings'] = \array_map($this->formatDeleted(...), $bookings->listDeleted());
        $this->data['admin']['types'] = \array_map($this->formatType(...), (new BookingType($db))->listAll());
        $this->data['admin']['resources'] = \array_map($this->formatResource(...), (new BookingResource($db))->listAll());
        $companyHours = $this->osmium->config->company->openingHours ?? null;
        $this->data['admin']['settings'] = BookingConfig::get(companyHours: $companyHours);
        $this->data['admin']['companyHours'] = BookingConfig::hoursFor(companyHours: $companyHours, breaks: []);
        $this->data['admin']['settings']['notifyEmailsText'] = \implode("\n", $this->data['admin']['settings']['notifyEmails']);
        $this->data['admin']['steps'] = BookingConfig::STEPS;
        $this->data['admin']['days'] = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

        $this->setView('bookings/index.phtml');
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
                'delete' => $this->deleteBooking($id),
                'restore' => $this->restoreBooking($id),
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
    // Bookings
    // =========================================================================

    private function confirm(int $id): array
    {
        $model = new Booking($this->osmium->dataSource);
        $booking = $this->findOpen(model: $model, id: $id, allowed: ['requested'], verb: 'confirmed');
        $this->refuseIfClash(model: $model, booking: $booking, resourceId: $booking['resource_id'] !== null ? (int) $booking['resource_id'] : null, startsAt: $booking['starts_at'], endsAt: $booking['ends_at']);

        $model->setStatus(id: $id, status: 'confirmed', sequence: (int) $booking['ics_sequence']);
        $this->logChange(action: 'Confirmed', id: $id, name: $booking['customer_name']);
        (new BookingNotifier($this->osmium))->confirmed($model->get($id));

        return ['success' => true, 'id' => $id];
    }

    private function decline(int $id): array
    {
        $model = new Booking($this->osmium->dataSource);
        $booking = $this->findOpen(model: $model, id: $id, allowed: ['requested'], verb: 'declined');

        $model->setStatus(id: $id, status: 'declined', sequence: (int) $booking['ics_sequence']);
        $this->logChange(action: 'Declined', id: $id, name: $booking['customer_name']);
        (new BookingNotifier($this->osmium))->declined($model->get($id));

        return ['success' => true, 'id' => $id];
    }

    private function cancel(int $id): array
    {
        $model = new Booking($this->osmium->dataSource);
        $booking = $this->findOpen(model: $model, id: $id, allowed: ['requested', 'confirmed'], verb: 'cancelled');

        $model->setStatus(id: $id, status: 'cancelled', sequence: (int) $booking['ics_sequence'] + 1);
        $this->logChange(action: 'Cancelled', id: $id, name: $booking['customer_name']);
        (new BookingNotifier($this->osmium))->cancelled($model->get($id));

        return ['success' => true, 'id' => $id];
    }

    private function reschedule(int $id, array $input): array
    {
        $model = new Booking($this->osmium->dataSource);
        $booking = $model->get($id);
        $missing = $booking === null || $booking['deleted_at'] !== null;
        if ($missing) throw new \InvalidArgumentException('Booking not found');

        $typeId = (int) ($input['type_id'] ?? 0);
        $type = (new BookingType($this->osmium->dataSource))->get($typeId);
        if ($type === null) throw new \InvalidArgumentException('Choose what is being booked');

        $resourceId = (int) ($input['resource_id'] ?? 0);
        $hasResource = $resourceId > 0;
        $resourceMissing = $hasResource && (new BookingResource($this->osmium->dataSource))->get($resourceId) === null;
        if ($resourceMissing) throw new \InvalidArgumentException('Choose who or what is booked');

        $start = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', \trim((string) ($input['date'] ?? '')) . ' ' . \trim((string) ($input['time'] ?? '')));
        $startBad = $start === false;
        if ($startBad) throw new \InvalidArgumentException('Enter a real date and time');

        $startsAt = $start->format('Y-m-d H:i:s');
        $endsAt = $start->modify('+' . (int) $type['duration_minutes'] . ' minutes')->format('Y-m-d H:i:s');
        $resourceValue = $hasResource ? $resourceId : null;

        $isConfirmed = $booking['status'] === 'confirmed';
        if ($isConfirmed) $this->refuseIfClash(model: $model, booking: $booking, resourceId: $resourceValue, startsAt: $startsAt, endsAt: $endsAt);

        $timeChanged = $startsAt !== $booking['starts_at'] || $endsAt !== $booking['ends_at'] || $resourceValue !== ($booking['resource_id'] !== null ? (int) $booking['resource_id'] : null);
        $sequence = (int) $booking['ics_sequence'] + ($isConfirmed && $timeChanged ? 1 : 0);
        $notes = \trim((string) ($input['admin_notes'] ?? ''));

        $model->reschedule(id: $id, typeId: $typeId, resourceId: $resourceValue, startsAt: $startsAt, endsAt: $endsAt, adminNotes: $notes !== '' ? $notes : null, sequence: $sequence);
        $this->logChange(action: 'Updated', id: $id, name: $booking['customer_name']);

        $tellCustomer = $isConfirmed && $timeChanged;
        if ($tellCustomer) (new BookingNotifier($this->osmium))->changed($model->get($id));

        return ['success' => true, 'id' => $id];
    }

    private function deleteBooking(int $id): array
    {
        $model = new Booking($this->osmium->dataSource);
        $booking = $model->get($id);
        $missing = $booking === null || $booking['deleted_at'] !== null;
        if ($missing) throw new \InvalidArgumentException('Booking not found');

        $model->softDelete($id);
        $this->logChange(action: 'Deleted', id: $id, name: $booking['customer_name']);

        return ['success' => true, 'id' => $id];
    }

    private function restoreBooking(int $id): array
    {
        $model = new Booking($this->osmium->dataSource);
        $booking = $model->get($id);
        if (!$booking) throw new \InvalidArgumentException('Booking not found');

        $model->restore($id);
        $this->logChange(action: 'Restored', id: $id, name: $booking['customer_name']);

        return ['success' => true, 'id' => $id];
    }

    private function permanentlyDelete(int $id): array
    {
        $notAdmin = !$this->isAdmin();
        if ($notAdmin) throw new \Exception('Unauthorized');

        $model = new Booking($this->osmium->dataSource);
        $booking = $model->get($id);
        if (!$booking) throw new \InvalidArgumentException('Booking not found');

        $this->logChange(action: 'Permanently deleted', id: $id, name: $booking['customer_name']);
        $model->hardDelete($id);

        return ['success' => true, 'id' => $id];
    }

    private function purgeDeleted(): array
    {
        $notAdmin = !$this->isAdmin();
        if ($notAdmin) throw new \Exception('Unauthorized');

        $count = (new Booking($this->osmium->dataSource))->purgeDeleted();
        $this->admin->model->changelog->log(description: "Purged {$count} deleted booking(s)", recordType: self::RECORD_TYPE, recordId: 0);

        return ['success' => true, 'count' => $count];
    }

    /**
     * @param list<string> $allowed
     */
    private function findOpen(Booking $model, int $id, array $allowed, string $verb): array
    {
        $booking = $model->get($id);
        $missing = $booking === null || $booking['deleted_at'] !== null;
        if ($missing) throw new \InvalidArgumentException('Booking not found');

        $wrongState = !\in_array($booking['status'], $allowed, true);
        if ($wrongState) throw new \InvalidArgumentException('This booking is ' . $booking['status'] . ' and cannot be ' . $verb);

        return $booking;
    }

    private function refuseIfClash(Booking $model, array $booking, ?int $resourceId, string $startsAt, string $endsAt): void
    {
        $clash = $model->findOverlap(excludeId: (int) $booking['id'], resourceId: $resourceId, startsAt: $startsAt, endsAt: $endsAt);
        if ($clash === null) return;

        $when = (new \DateTimeImmutable($clash['starts_at']))->format('D j M, H:i') . ' to ' . (new \DateTimeImmutable($clash['ends_at']))->format('H:i');
        throw new \InvalidArgumentException('That clashes with a confirmed booking for ' . $clash['customer_name'] . ' (' . $when . '). Change the time first.');
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
        $model = new BookingType($this->osmium->dataSource);
        $id = (int) ($input['id'] ?? 0);

        $isNew = $id === 0;
        if (!$isNew && $model->get($id) === null) throw new \InvalidArgumentException('Not found');

        $savedId = $isNew ? $model->create($details) : $id;
        if (!$isNew) $model->update(id: $id, details: $details);
        $this->admin->model->changelog->log(description: ($isNew ? 'Created' : 'Updated') . ' booking type: ' . $name, recordType: 'booking_type', recordId: $savedId);

        return ['success' => true, 'id' => $savedId];
    }

    private function deleteType(int $id): array
    {
        $model = new BookingType($this->osmium->dataSource);
        $type = \array_values(\array_filter($model->listAll(), static fn(array $row): bool => (int) $row['id'] === $id))[0] ?? null;
        if ($type === null) throw new \InvalidArgumentException('Not found');

        $inUse = (int) $type['booking_count'] > 0;
        if ($inUse) throw new \InvalidArgumentException('Bookings use this, so it cannot be deleted. Turn it off instead.');

        $model->delete($id);
        $this->admin->model->changelog->log(description: 'Deleted booking type: ' . $type['name'], recordType: 'booking_type', recordId: $id);

        return ['success' => true, 'id' => $id];
    }

    private function saveResource(array $input): array
    {
        $name = \trim((string) ($input['name'] ?? ''));
        $nameBad = $name === '' || \mb_strlen($name) > 100;
        if ($nameBad) throw new \InvalidArgumentException('Name is required and limited to 100 characters');

        $model = new BookingResource($this->osmium->dataSource);
        $id = (int) ($input['id'] ?? 0);
        $enabled = !empty($input['enabled']);
        $sortOrder = \max(0, (int) ($input['sort_order'] ?? 0));

        $isNew = $id === 0;
        if (!$isNew && $model->get($id) === null) throw new \InvalidArgumentException('Not found');

        $savedId = $isNew ? $model->create(name: $name, enabled: $enabled, sortOrder: $sortOrder) : $id;
        if (!$isNew) $model->update(id: $id, name: $name, enabled: $enabled, sortOrder: $sortOrder);
        $this->admin->model->changelog->log(description: ($isNew ? 'Created' : 'Updated') . ' booking resource: ' . $name, recordType: 'booking_resource', recordId: $savedId);

        return ['success' => true, 'id' => $savedId];
    }

    private function deleteResource(int $id): array
    {
        $model = new BookingResource($this->osmium->dataSource);
        $resource = \array_values(\array_filter($model->listAll(), static fn(array $row): bool => (int) $row['id'] === $id))[0] ?? null;
        if ($resource === null) throw new \InvalidArgumentException('Not found');

        $inUse = (int) $resource['booking_count'] > 0;
        if ($inUse) throw new \InvalidArgumentException('Bookings use this, so it cannot be deleted. Turn it off instead.');

        $model->delete($id);
        $this->admin->model->changelog->log(description: 'Deleted booking resource: ' . $resource['name'], recordType: 'booking_resource', recordId: $id);

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

        BookingConfig::save([
            'windows' => $windows,
            'breaks' => $breaks,
            'stepMinutes' => $input['step_minutes'] ?? 0,
            'minNoticeHours' => $input['min_notice_hours'] ?? -1,
            'maxDaysAhead' => $input['max_days_ahead'] ?? 0,
            'notifyEmails' => (string) ($input['notify_emails'] ?? ''),
        ]);
        $this->admin->model->changelog->log(description: 'Updated booking settings', recordType: 'settings', recordId: 0);

        return ['success' => true];
    }

    // =========================================================================
    // Formatting
    // =========================================================================

    private function formatBooking(array $row): array
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
            'bookingCount' => (int) $row['booking_count'],
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
            'bookingCount' => (int) $row['booking_count'],
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
