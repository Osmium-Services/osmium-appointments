(function () {
    'use strict';

    const config = window.OsmiumAppointmentsConfig || {};
    const actionUrl = config.basePath + 'appointments/action/';

    function byId(id) {
        return document.getElementById(id);
    }

    function show(resultId, kind, message) {
        const box = byId(resultId);
        box.className = 'alert alert-' + kind + (resultId === 'settingsResult' ? ' mb-0 py-2' : '');
        box.textContent = message;
    }

    function clear(resultId) {
        const box = byId(resultId);
        box.className = 'alert d-none';
        box.textContent = '';
    }

    async function post(payload) {
        const response = await fetch(actionUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({ csrf_token: window.csrfToken }, payload))
        });

        return response.json();
    }

    // Saves a form's payload, reloads on success and shows the problem in the form's own message box otherwise
    async function submit(event, resultId, payload) {
        event.preventDefault();
        clear(resultId);

        try {
            const result = await post(payload);
            if (result.success) {
                window.location.reload();
                return;
            }

            show(resultId, 'danger', result.error || 'Failed to save');
        } catch (error) {
            show(resultId, 'danger', 'An error occurred while saving');
        }
    }

    function openModal(id) {
        new bootstrap.Modal(byId(id)).show();
    }

    function openAppointment(appointment) {
        clear('appointmentResult');
        byId('appointmentId').value = appointment.id;
        byId('appointmentWho').textContent = appointment.reference + ' (' + appointment.status + ')';
        byId('appointmentTheirNotes').textContent = appointment.customerNotes ? 'They wrote: ' + appointment.customerNotes : '';
        byId('appointmentType').value = appointment.typeId;
        byId('appointmentResource').value = appointment.resourceId === '' ? '0' : appointment.resourceId;
        byId('appointmentDate').value = appointment.date;
        byId('appointmentTime').value = appointment.time;
        byId('appointmentNotes').value = appointment.adminNotes;
        openModal('appointmentModal');
    }

    function openType(type) {
        clear('typeResult');
        const isEdit = Boolean(type);
        byId('typeModalTitle').textContent = isEdit ? 'Edit' : 'Add something to book';
        byId('typeId').value = isEdit ? type.id : '';
        byId('typeName').value = isEdit ? type.name : '';
        byId('typeDescription').value = isEdit ? type.description : '';
        byId('typeMinutes').value = isEdit ? type.minutes : 60;
        byId('typePrice').value = isEdit ? type.priceText : '';
        byId('typeOrder').value = isEdit ? type.sortOrder : 0;
        byId('typeEnabled').checked = isEdit ? type.enabled : true;
        openModal('typeModal');
    }

    function openResource(resource) {
        clear('resourceResult');
        const isEdit = Boolean(resource);
        byId('resourceModalTitle').textContent = isEdit ? 'Edit' : 'Add someone or something';
        byId('resourceId').value = isEdit ? resource.id : '';
        byId('resourceName').value = isEdit ? resource.name : '';
        byId('resourceOrder').value = isEdit ? resource.sortOrder : 0;
        byId('resourceEnabled').checked = isEdit ? resource.enabled : true;
        openModal('resourceModal');
    }

    function settingsPayload() {
        const payload = {
            action: 'save_settings',
            step_minutes: byId('settingsStep').value,
            min_notice_hours: byId('settingsNotice').value,
            max_days_ahead: byId('settingsAhead').value,
            notify_emails: byId('settingsEmails').value
        };

        for (let day = 1; day <= 7; day++) {
            payload['window_start_' + day] = byId('wfrom_' + day).value;
            payload['window_end_' + day] = byId('wto_' + day).value;
            payload['break_start_' + day] = byId('bfrom_' + day).value;
            payload['break_end_' + day] = byId('bto_' + day).value;
        }

        return payload;
    }

    function init() {
        // Row actions come from the ListViews; each Edit fires list:action with its own event name and the row's id
        document.addEventListener('list:action', function (e) {
            if (e.detail.action === 'edit') openAppointment(config.appointments[e.detail.id]);
            if (e.detail.action === 'add-type') openType(null);
            if (e.detail.action === 'add-resource') openResource(null);
            if (e.detail.action === 'edit-type') openType(config.types[e.detail.id]);
            if (e.detail.action === 'edit-resource') openResource(config.resources[e.detail.id]);
        });

        byId('appointmentForm').addEventListener('submit', function (e) {
            submit(e, 'appointmentResult', {
                action: 'reschedule',
                id: byId('appointmentId').value,
                type_id: byId('appointmentType').value,
                resource_id: byId('appointmentResource').value,
                date: byId('appointmentDate').value,
                time: byId('appointmentTime').value,
                admin_notes: byId('appointmentNotes').value
            });
        });

        byId('typeForm').addEventListener('submit', function (e) {
            submit(e, 'typeResult', {
                action: 'save_type',
                id: byId('typeId').value,
                name: byId('typeName').value,
                description: byId('typeDescription').value,
                duration_minutes: byId('typeMinutes').value,
                price_text: byId('typePrice').value,
                sort_order: byId('typeOrder').value,
                enabled: byId('typeEnabled').checked
            });
        });

        byId('resourceForm').addEventListener('submit', function (e) {
            submit(e, 'resourceResult', {
                action: 'save_resource',
                id: byId('resourceId').value,
                name: byId('resourceName').value,
                sort_order: byId('resourceOrder').value,
                enabled: byId('resourceEnabled').checked
            });
        });

        byId('settingsForm').addEventListener('submit', function (e) {
            submit(e, 'settingsResult', settingsPayload());
        });
    }

    document.addEventListener('DOMContentLoaded', init);
})();
