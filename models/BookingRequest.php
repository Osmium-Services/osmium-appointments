<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumBookings\Models;

/**
 * Turns the public form's fields into a clean request, or refuses with a message to show the customer.
 * Whether the type, person and time exist and fit the opening hours is checked later, against the database.
 */
class BookingRequest
{
    public const TECHNICAL_PROBLEM = 'Sorry, something went wrong. Please try again.';

    /**
     * @param array<string, mixed> $post
     * @return array{typeId: int, resourceId: ?int, date: string, time: string, name: string, email: string, phone: ?string, notes: ?string}
     * @throws \InvalidArgumentException
     */
    public static function parse(array $post): array
    {
        $name = \trim((string) ($post['name'] ?? ''));
        $nameBad = $name === '' || \mb_strlen($name) > 100;
        if ($nameBad) throw new \InvalidArgumentException('Please enter your name');

        $email = \trim((string) ($post['email'] ?? ''));
        $emailBad = \filter_var($email, FILTER_VALIDATE_EMAIL) === false || \strlen($email) > 150;
        if ($emailBad) throw new \InvalidArgumentException('Please enter a valid email address');

        $phone = self::nullIfEmpty((string) ($post['phone'] ?? ''));
        $phoneBad = $phone !== null && !\preg_match('/^[0-9+()\-\s]{6,30}$/', $phone);
        if ($phoneBad) throw new \InvalidArgumentException('Please enter a valid phone number, or leave it empty');

        $notes = self::nullIfEmpty((string) ($post['notes'] ?? ''));
        $notesTooLong = $notes !== null && \mb_strlen($notes) > 1000;
        if ($notesTooLong) throw new \InvalidArgumentException('Please keep your message under 1000 characters');

        $typeId = (int) ($post['type_id'] ?? 0);
        $noType = $typeId < 1;
        if ($noType) throw new \InvalidArgumentException('Please choose what you would like to book');

        $resourceId = (int) ($post['resource_id'] ?? 0);

        return [
            'typeId' => $typeId,
            'resourceId' => $resourceId > 0 ? $resourceId : null,
            'date' => \trim((string) ($post['date'] ?? '')),
            'time' => \trim((string) ($post['time'] ?? '')),
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'notes' => $notes,
        ];
    }

    private static function nullIfEmpty(string $value): ?string
    {
        $trimmed = \trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
