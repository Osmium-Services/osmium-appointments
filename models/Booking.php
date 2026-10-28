<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumBookings\Models;

use Osmium\Core\Library\OsmiumPDO;
use Osmium\Core\Models\Model;

/**
 * The bookings: a customer's request and what the business did with it. Soft-deleted first, removed for good from the bin.
 */
class Booking extends Model
{
    public const STATUSES = ['requested', 'confirmed', 'declined', 'cancelled'];

    protected ?string $sqlDir = __DIR__ . '/sql';

    public function __construct(OsmiumPDO $database)
    {
        parent::__construct(database: $database, tableName: 'bookings');
    }

    /** Every booking not in the bin, requests first, then newest time first, with its type and person */
    public function listAll(): array
    {
        return $this->rows('booking-list.sql');
    }

    public function listDeleted(): array
    {
        return $this->rows('booking-list-deleted.sql');
    }

    public function countRequested(): int
    {
        $this->database->query($this->loadSqlFile('booking-count-requested.sql'));
        $row = $this->database->single();

        return (int) ($row['total'] ?? 0);
    }

    public function get(int $id): ?array
    {
        return $this->one(file: 'booking-get.sql', param: ':id', value: $id);
    }

    public function getByReference(string $reference): ?array
    {
        return $this->one(file: 'booking-get-by-reference.sql', param: ':reference', value: $reference);
    }

    /**
     * @param array{typeId: int, resourceId: ?int, name: string, email: string, phone: ?string, notes: ?string} $request
     * @return array{id: int, reference: string}
     */
    public function create(array $request, string $startsAt, string $endsAt): array
    {
        $reference = $this->newReference();

        $this->database->query($this->loadSqlFile('booking-insert.sql'));
        $this->database->bind(param: ':reference', value: $reference);
        $this->database->bind(param: ':type_id', value: $request['typeId']);
        $this->database->bind(param: ':resource_id', value: $request['resourceId']);
        $this->database->bind(param: ':customer_name', value: $request['name']);
        $this->database->bind(param: ':customer_email', value: $request['email']);
        $this->database->bind(param: ':customer_phone', value: $request['phone']);
        $this->database->bind(param: ':customer_notes', value: $request['notes']);
        $this->database->bind(param: ':starts_at', value: $startsAt);
        $this->database->bind(param: ':ends_at', value: $endsAt);
        $this->database->execute();

        return ['id' => (int) $this->database->lastInsertId(), 'reference' => $reference];
    }

    public function reschedule(int $id, int $typeId, ?int $resourceId, string $startsAt, string $endsAt, ?string $adminNotes, int $sequence): void
    {
        $this->database->query($this->loadSqlFile('booking-reschedule.sql'));
        $this->database->bind(param: ':id', value: $id);
        $this->database->bind(param: ':type_id', value: $typeId);
        $this->database->bind(param: ':resource_id', value: $resourceId);
        $this->database->bind(param: ':starts_at', value: $startsAt);
        $this->database->bind(param: ':ends_at', value: $endsAt);
        $this->database->bind(param: ':admin_notes', value: $adminNotes);
        $this->database->bind(param: ':ics_sequence', value: $sequence);
        $this->database->execute();
    }

    public function setStatus(int $id, string $status, int $sequence): void
    {
        $this->database->query($this->loadSqlFile('booking-set-status.sql'));
        $this->database->bind(param: ':id', value: $id);
        $this->database->bind(param: ':status', value: $status);
        $this->database->bind(param: ':ics_sequence', value: $sequence);
        $this->database->execute();
    }

    /**
     * What other services have stored against the booking, such as a calendar event id: service id => value.
     * Each service keeps to its own key, so one can never overwrite another's.
     *
     * @return array<string, mixed>
     */
    public static function externalRefs(array $booking): array
    {
        $decoded = \json_decode((string) ($booking['external_refs'] ?? ''), associative: true);

        return \is_array($decoded) ? $decoded : [];
    }

    public function setExternalRef(int $id, string $serviceId, mixed $value): void
    {
        $booking = $this->get($id);
        if ($booking === null) return;

        $refs = self::externalRefs($booking);
        $refs[$serviceId] = $value;

        $this->database->query($this->loadSqlFile('booking-set-refs.sql'));
        $this->database->bind(param: ':id', value: $id);
        $this->database->bind(param: ':external_refs', value: \json_encode($refs));
        $this->database->execute();
    }

    /** A confirmed booking that clashes with this time for the same person (or with no person), other than the booking itself */
    public function findOverlap(int $excludeId, ?int $resourceId, string $startsAt, string $endsAt): ?array
    {
        $this->database->query($this->loadSqlFile('booking-overlapping.sql'));
        $this->database->bind(param: ':id', value: $excludeId);
        $this->database->bind(param: ':no_resource', value: $resourceId === null ? 1 : 0);
        $this->database->bind(param: ':resource_id', value: $resourceId);
        $this->database->bind(param: ':starts_at', value: $startsAt);
        $this->database->bind(param: ':ends_at', value: $endsAt);
        $result = $this->database->single();

        return $result ?: null;
    }

    /** The same person asking for the same thing at the same time twice */
    public function isDuplicate(string $email, string $startsAt, int $typeId): bool
    {
        $this->database->query($this->loadSqlFile('booking-duplicate.sql'));
        $this->database->bind(param: ':email', value: $email);
        $this->database->bind(param: ':starts_at', value: $startsAt);
        $this->database->bind(param: ':type_id', value: $typeId);

        return (bool) $this->database->single();
    }

    public function softDelete(int $id): void
    {
        $this->run(file: 'booking-soft-delete.sql', id: $id);
    }

    public function restore(int $id): void
    {
        $this->run(file: 'booking-restore.sql', id: $id);
    }

    public function hardDelete(int $id): void
    {
        $this->run(file: 'booking-hard-delete.sql', id: $id);
    }

    /** @return int How many bookings were removed */
    public function purgeDeleted(): int
    {
        $this->database->query($this->loadSqlFile('booking-purge-deleted.sql'));
        $this->database->execute();

        return $this->database->rowCount();
    }

    /** Ten characters from letters and digits that are hard to misread, unique among bookings */
    private function newReference(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        do {
            $reference = '';
            for ($i = 0; $i < 10; $i++) {
                $reference .= $alphabet[\random_int(0, \strlen($alphabet) - 1)];
            }
            $this->database->query($this->loadSqlFile('booking-reference-taken.sql'));
            $this->database->bind(param: ':reference', value: $reference);
            $taken = (bool) $this->database->single();
        } while ($taken);

        return $reference;
    }

    private function rows(string $file): array
    {
        $this->database->query($this->loadSqlFile($file));

        return $this->database->resultset();
    }

    private function one(string $file, string $param, int|string $value): ?array
    {
        $this->database->query($this->loadSqlFile($file));
        $this->database->bind(param: $param, value: $value);
        $result = $this->database->single();

        return $result ?: null;
    }

    private function run(string $file, int $id): void
    {
        $this->database->query($this->loadSqlFile($file));
        $this->database->bind(param: ':id', value: $id);
        $this->database->execute();
    }
}
