<?php

declare(strict_types=1);

use Osmium\Modules\Admin\Core\ListView;

/**
 * Appointments lists, rendered by Osmium\Modules\Admin\Core\ListView. Row fields are prepared by
 * AppointmentsController::formatAppointment(), formatType(), formatResource() and formatDeleted().
 */
require_once 'app/core/admin/services/ChangedByCell.php';

$changedByColumn = ['key' => 'changed_by', 'label' => '', 'type' => 'html', 'sortable' => false, 'render' => fn(array $row, array $context): string => \Osmium\Modules\Admin\Services\ChangedByCell::html($row, (string)($context['avatarUrl'] ?? ''))];

return [
    'active' => [
        'id' => 'osmium-appointments',
        'title' => 'Appointments',
        'noun' => ['appointment', 'appointments'],
        'endpoint' => 'appointments/action/',
        'note' => 'Requests wait at the top until you confirm or decline them. The customer is emailed each time.',
        'emptyText' => 'No appointments yet.',
        'order' => null, // Server order: requests first, then latest time first
        'columns' => [
            ['key' => 'statusBadge', 'label' => 'Status', 'type' => 'raw', 'nowrap' => true],
            ['key' => 'whenText', 'label' => 'When', 'type' => 'text', 'nowrap' => true],
            ['key' => 'customerHtml', 'label' => 'Customer', 'type' => 'raw', 'fill' => true],
            ['key' => 'typeName', 'label' => 'What', 'type' => 'text'],
            ['key' => 'resourceName', 'label' => 'With', 'type' => 'text'],
        ],
        'actions' => [
            [
                'label' => 'Confirm',
                'icon' => 'bx-check',
                'action' => 'confirm',
                'class' => 'text-success',
                'visible' => fn(array $row, array $context): bool => $row['status'] === 'requested',
            ],
            [
                'label' => 'Decline',
                'icon' => 'bx-x',
                'action' => 'decline',
                'confirm' => 'Decline this request? The customer will be emailed.',
                'visible' => fn(array $row, array $context): bool => $row['status'] === 'requested',
            ],
            ['label' => 'Edit', 'icon' => 'bx-edit-alt', 'event' => 'edit'],
            [
                'label' => 'Cancel appointment',
                'icon' => 'bx-calendar-x',
                'action' => 'cancel',
                'confirm' => 'Cancel this appointment? The customer will be emailed.',
                'visible' => fn(array $row, array $context): bool => \in_array($row['status'], ['requested', 'confirmed'], true),
            ],
            [
                'label' => 'Delete',
                'icon' => 'bx-trash',
                'action' => 'delete',
                'confirm' => 'Delete this appointment? It can be restored from Deleted Appointments. The customer is not emailed.',
                'danger' => true,
            ],
        ],
    ],

    'deleted' => ListView::deletedPreset([
        'id' => 'osmium-appointments-deleted',
        'title' => 'Deleted Appointments',
        'noun' => ['item', 'items'],
        'endpoint' => 'appointments/action/',
        'hideWhenEmpty' => false,
        'headerButton' => [
            'label' => 'Purge All',
            'icon' => 'bx-trash',
            'class' => 'btn-outline-danger',
            'action' => 'purge_deleted',
            'confirm' => 'Permanently delete all deleted appointments? This cannot be undone!',
            'visible' => fn(array $rows, array $context): bool => (bool) $context['isAdmin'],
        ],
        'columns' => [
            $changedByColumn,
            ['key' => 'name', 'label' => 'Appointment', 'type' => 'text', 'strong' => true, 'fill' => true],
            ['key' => 'deletedDate', 'label' => 'Deleted', 'type' => 'text', 'small' => true, 'nowrap' => true],
        ],
    ]),

    'types' => [
        'id' => 'osmium-appointments-types',
        'title' => 'What can be booked',
        'noun' => ['type', 'types'],
        'endpoint' => 'appointments/action/',
        'emptyText' => 'Nothing to book yet - use Add to create the first.',
        'headerButton' => ['label' => 'Add', 'icon' => 'bx-plus', 'class' => 'btn-primary btn-sm', 'event' => 'add-type'],
        'order' => null,
        'columns' => [
            ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'strong' => true, 'fill' => true],
            ['key' => 'minutes', 'label' => 'Minutes', 'type' => 'text', 'nowrap' => true],
            ['key' => 'priceText', 'label' => 'Price', 'type' => 'text', 'nowrap' => true],
            ['key' => 'enabledBadge', 'label' => 'On', 'type' => 'raw', 'nowrap' => true],
        ],
        'actions' => [
            ['label' => 'Edit', 'icon' => 'bx-edit-alt', 'event' => 'edit-type'],
            [
                'label' => 'Delete',
                'icon' => 'bx-trash',
                'action' => 'delete_type',
                'confirm' => 'Delete this? (Not possible once an appointment uses it: turn it off instead.)',
                'danger' => true,
            ],
        ],
    ],

    'resources' => [
        'id' => 'osmium-appointments-resources',
        'title' => 'Who or what is booked',
        'noun' => ['one', 'ones'],
        'endpoint' => 'appointments/action/',
        'subtitle' => 'Optional: people, rooms or equipment. With none, customers simply book the business.',
        'emptyText' => 'None - appointments are for the business as a whole.',
        'headerButton' => ['label' => 'Add', 'icon' => 'bx-plus', 'class' => 'btn-primary btn-sm', 'event' => 'add-resource'],
        'order' => null,
        'columns' => [
            ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'strong' => true, 'fill' => true],
            ['key' => 'enabledBadge', 'label' => 'On', 'type' => 'raw', 'nowrap' => true],
        ],
        'actions' => [
            ['label' => 'Edit', 'icon' => 'bx-edit-alt', 'event' => 'edit-resource'],
            [
                'label' => 'Delete',
                'icon' => 'bx-trash',
                'action' => 'delete_resource',
                'confirm' => 'Delete this? (Not possible once an appointment uses it: turn it off instead.)',
                'danger' => true,
            ],
        ],
    ],
];
