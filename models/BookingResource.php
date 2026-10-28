<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumBookings\Models;

use Osmium\Core\Library\OsmiumPDO;
use Osmium\Core\Models\Model;

/**
 * Who or what is booked: a person, a room, a piece of equipment. Optional: a site can run without any.
 */
class BookingResource extends Model
{
    protected ?string $sqlDir = __DIR__ . '/sql';

    public function __construct(OsmiumPDO $database)
    {
        parent::__construct(database: $database, tableName: 'booking_resources');
    }

    public function listAll(): array
    {
        $this->database->query($this->loadSqlFile('booking-resource-list.sql'));

        return $this->database->resultset();
    }

    public function get(int $id): ?array
    {
        $this->database->query($this->loadSqlFile('booking-resource-get.sql'));
        $this->database->bind(param: ':id', value: $id);
        $result = $this->database->single();

        return $result ?: null;
    }

    public function create(string $name, bool $enabled, int $sortOrder): int
    {
        $this->database->query($this->loadSqlFile('booking-resource-insert.sql'));
        $this->database->bind(param: ':name', value: $name);
        $this->database->bind(param: ':enabled', value: $enabled ? 1 : 0);
        $this->database->bind(param: ':sort_order', value: $sortOrder);
        $this->database->execute();

        return (int) $this->database->lastInsertId();
    }

    public function update(int $id, string $name, bool $enabled, int $sortOrder): void
    {
        $this->database->query($this->loadSqlFile('booking-resource-update.sql'));
        $this->database->bind(param: ':id', value: $id);
        $this->database->bind(param: ':name', value: $name);
        $this->database->bind(param: ':enabled', value: $enabled ? 1 : 0);
        $this->database->bind(param: ':sort_order', value: $sortOrder);
        $this->database->execute();
    }

    public function delete(int $id): void
    {
        $this->database->query($this->loadSqlFile('booking-resource-delete.sql'));
        $this->database->bind(param: ':id', value: $id);
        $this->database->execute();
    }
}
