<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumAppointments\Models;

use Osmium\Core\Library\OsmiumPDO;
use Osmium\Core\Models\Model;

/**
 * Who or what is booked: a person, a room, a piece of equipment. Optional: a site can run without any. Each may have a daily break (a person's lunch) that no appointment can overlap.
 */
class AppointmentResource extends Model
{
    protected ?string $sqlDir = __DIR__ . '/sql';

    public function __construct(OsmiumPDO $database)
    {
        parent::__construct(database: $database, tableName: 'appointment_resources');
    }

    public function listAll(): array
    {
        $this->database->query($this->loadSqlFile('appointment-resource-list.sql'));

        return $this->database->resultset();
    }

    public function get(int $id): ?array
    {
        $this->database->query($this->loadSqlFile('appointment-resource-get.sql'));
        $this->database->bind(param: ':id', value: $id);
        $result = $this->database->single();

        return $result ?: null;
    }

    public function create(string $name, bool $enabled, int $sortOrder, ?string $breakStart = null, ?string $breakEnd = null): int
    {
        $this->database->query($this->loadSqlFile('appointment-resource-insert.sql'));
        $this->database->bind(param: ':name', value: $name);
        $this->database->bind(param: ':enabled', value: $enabled ? 1 : 0);
        $this->database->bind(param: ':break_start', value: $breakStart);
        $this->database->bind(param: ':break_end', value: $breakEnd);
        $this->database->bind(param: ':sort_order', value: $sortOrder);
        $this->database->execute();

        return (int) $this->database->lastInsertId();
    }

    public function update(int $id, string $name, bool $enabled, int $sortOrder, ?string $breakStart = null, ?string $breakEnd = null): void
    {
        $this->database->query($this->loadSqlFile('appointment-resource-update.sql'));
        $this->database->bind(param: ':id', value: $id);
        $this->database->bind(param: ':name', value: $name);
        $this->database->bind(param: ':enabled', value: $enabled ? 1 : 0);
        $this->database->bind(param: ':break_start', value: $breakStart);
        $this->database->bind(param: ':break_end', value: $breakEnd);
        $this->database->bind(param: ':sort_order', value: $sortOrder);
        $this->database->execute();
    }

    public function delete(int $id): void
    {
        $this->database->query($this->loadSqlFile('appointment-resource-delete.sql'));
        $this->database->bind(param: ':id', value: $id);
        $this->database->execute();
    }
}
