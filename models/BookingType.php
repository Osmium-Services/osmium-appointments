<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumBookings\Models;

use Osmium\Core\Library\OsmiumPDO;
use Osmium\Core\Models\Model;

/**
 * What can be booked: a name, a length and an optional price shown as text (no payment is taken).
 */
class BookingType extends Model
{
    protected ?string $sqlDir = __DIR__ . '/sql';

    public function __construct(OsmiumPDO $database)
    {
        parent::__construct(database: $database, tableName: 'booking_types');
    }

    public function listAll(): array
    {
        $this->database->query($this->loadSqlFile('booking-type-list.sql'));

        return $this->database->resultset();
    }

    public function get(int $id): ?array
    {
        $this->database->query($this->loadSqlFile('booking-type-get.sql'));
        $this->database->bind(param: ':id', value: $id);
        $result = $this->database->single();

        return $result ?: null;
    }

    /**
     * @param array{name: string, description: ?string, durationMinutes: int, priceText: ?string, enabled: bool, sortOrder: int} $details
     */
    public function create(array $details): int
    {
        $this->database->query($this->loadSqlFile('booking-type-save-insert.sql'));
        $this->bindDetails($details);
        $this->database->execute();

        return (int) $this->database->lastInsertId();
    }

    /**
     * @param array{name: string, description: ?string, durationMinutes: int, priceText: ?string, enabled: bool, sortOrder: int} $details
     */
    public function update(int $id, array $details): void
    {
        $this->database->query($this->loadSqlFile('booking-type-save-update.sql'));
        $this->database->bind(param: ':id', value: $id);
        $this->bindDetails($details);
        $this->database->execute();
    }

    public function delete(int $id): void
    {
        $this->database->query($this->loadSqlFile('booking-type-delete.sql'));
        $this->database->bind(param: ':id', value: $id);
        $this->database->execute();
    }

    private function bindDetails(array $details): void
    {
        $this->database->bind(param: ':name', value: $details['name']);
        $this->database->bind(param: ':description', value: $details['description']);
        $this->database->bind(param: ':duration_minutes', value: $details['durationMinutes']);
        $this->database->bind(param: ':price_text', value: $details['priceText']);
        $this->database->bind(param: ':enabled', value: $details['enabled'] ? 1 : 0);
        $this->database->bind(param: ':sort_order', value: $details['sortOrder']);
    }
}
