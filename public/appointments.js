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

    // Attaches a handler when the element is on this page (the schedule page and the setup page share this script)
    function on(id, event, handler) {
        const node = byId(id);
        if (node) node.addEventListener(event, handler);
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
        byId('resourceBreakStart').value = isEdit ? resource.breakStart : '';
        byId('resourceBreakEnd').value = isEdit ? resource.breakEnd : '';
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

    // ---- Schedule: one day, a column for each person (or one column when there are none) ----

    const PX_PER_MINUTE = 1.3;

    function toMinutes(time) {
        const parts = time.split(':');

        return Number(parts[0]) * 60 + Number(parts[1]);
    }

    function pad(number) {
        return String(number).padStart(2, '0');
    }

    function clock(minutes) {
        return pad(Math.floor(minutes / 60)) + ':' + pad(minutes % 60);
    }

    function isoDate(date) {
        return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
    }

    function el(tag, className, styles) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (styles) Object.assign(node.style, styles);

        return node;
    }

    function shade(parent, className, fromMinutes, toMinutes_, origin) {
        if (toMinutes_ <= fromMinutes) return;

        parent.appendChild(el('div', 'sched-shade ' + className, {
            top: (fromMinutes - origin) * PX_PER_MINUTE + 'px',
            height: (toMinutes_ - fromMinutes) * PX_PER_MINUTE + 'px'
        }));
    }

    function renderSchedule(dateText) {
        const grid = byId('scheduleGrid');
        const date = new Date(dateText + 'T00:00:00');
        const weekday = (date.getDay() + 6) % 7 + 1;
        const hours = config.schedule.hours[weekday] || null;

        const today = Object.values(config.appointments).filter(function (item) {
            return item.date === dateText && (item.status === 'requested' || item.status === 'confirmed');
        });
        const people = Object.values(config.resources).filter(function (person) { return person.enabled; });
        const unassigned = today.filter(function (item) { return item.resourceId === ''; });

        grid.textContent = '';
        if (hours === null && today.length === 0) {
            grid.appendChild(el('div', 'sched-note')).textContent = 'Closed this day.';
            return;
        }

        // The visible hours: opening time, widened to take in anything already booked outside it
        let first = hours ? toMinutes(hours.open) : 24 * 60;
        let last = hours ? toMinutes(hours.close) : 0;
        today.forEach(function (item) {
            first = Math.min(first, toMinutes(item.time));
            last = Math.max(last, toMinutes(item.endTime));
        });
        const origin = Math.floor(first / 60) * 60;
        const end = Math.ceil(last / 60) * 60;
        const height = (end - origin) * PX_PER_MINUTE;

        const columns = people.map(function (person) { return { id: person.id, name: person.name, breakStart: person.breakStart, breakEnd: person.breakEnd }; });
        if (unassigned.length > 0 || columns.length === 0) {
            columns.push({ id: '', name: columns.length === 0 ? 'Appointments' : 'No one in particular', breakStart: '', breakEnd: '' });
        }

        const table = el('div', 'sched', { gridTemplateColumns: '3.5rem repeat(' + columns.length + ', minmax(7rem, 1fr))' });
        table.appendChild(el('div', 'sched-head'));
        columns.forEach(function (column) { table.appendChild(el('div', 'sched-head')).textContent = column.name; });

        const times = el('div', 'sched-times', { height: height + 'px' });
        for (let at = origin; at <= end; at += 60) {
            const label = el('div', 'sched-time', { top: (at - origin) * PX_PER_MINUTE + 'px' });
            label.textContent = clock(at);
            times.appendChild(label);
        }
        table.appendChild(times);

        columns.forEach(function (column) {
            const lane = el('div', 'sched-col', { height: height + 'px' });
            for (let at = origin; at < end; at += 60) {
                lane.appendChild(el('div', 'sched-line', { top: (at - origin) * PX_PER_MINUTE + 'px' }));
            }

            if (hours === null) {
                shade(lane, 'sched-closed', origin, end, origin);
            } else {
                shade(lane, 'sched-closed', origin, toMinutes(hours.open), origin);
                shade(lane, 'sched-closed', toMinutes(hours.close), end, origin);
                if (hours.breakStart) shade(lane, 'sched-break', toMinutes(hours.breakStart), toMinutes(hours.breakEnd), origin);
            }
            if (column.breakStart) shade(lane, 'sched-break', toMinutes(column.breakStart), toMinutes(column.breakEnd), origin);

            today.filter(function (item) { return String(item.resourceId) === String(column.id); }).forEach(function (item) {
                const block = el('button', 'sched-appt sched-' + item.status, {
                    top: (toMinutes(item.time) - origin) * PX_PER_MINUTE + 'px',
                    height: Math.max(18, (toMinutes(item.endTime) - toMinutes(item.time)) * PX_PER_MINUTE - 2) + 'px'
                });
                block.type = 'button';
                block.title = item.time + ' to ' + item.endTime + ' ' + item.customerName + ' (' + item.status + ')';
                block.appendChild(document.createTextNode(item.time + ' ' + item.customerName));
                block.appendChild(el('small')).textContent = item.typeName;
                block.addEventListener('click', function () { openAppointment(item); });
                lane.appendChild(block);
            });

            table.appendChild(lane);
        });

        grid.appendChild(table);
    }

    function initSchedule() {
        const input = byId('scheduleDate');
        const show = function (date) {
            input.value = isoDate(date);
            renderSchedule(input.value);
        };
        const shift = function (days) {
            const date = new Date(input.value + 'T00:00:00');
            date.setDate(date.getDate() + days);
            show(date);
        };

        on('scheduleToday', 'click', function () { show(new Date()); });
        on('schedulePrev', 'click', function () { shift(-1); });
        on('scheduleNext', 'click', function () { shift(1); });
        input.addEventListener('change', function () { if (input.value) renderSchedule(input.value); });
        show(new Date());
    }

    function init() {
        if (byId('schedule')) initSchedule();

        // Row actions come from the ListViews; each Edit fires list:action with its own event name and the row's id
        document.addEventListener('list:action', function (e) {
            if (e.detail.action === 'edit') openAppointment(config.appointments[e.detail.id]);
            if (e.detail.action === 'add-type') openType(null);
            if (e.detail.action === 'add-resource') openResource(null);
            if (e.detail.action === 'edit-type') openType(config.types[e.detail.id]);
            if (e.detail.action === 'edit-resource') openResource(config.resources[e.detail.id]);
        });

        on('appointmentForm', 'submit', function (e) {
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

        on('typeForm', 'submit', function (e) {
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

        on('resourceForm', 'submit', function (e) {
            submit(e, 'resourceResult', {
                action: 'save_resource',
                id: byId('resourceId').value,
                name: byId('resourceName').value,
                sort_order: byId('resourceOrder').value,
                break_start: byId('resourceBreakStart').value,
                break_end: byId('resourceBreakEnd').value,
                enabled: byId('resourceEnabled').checked
            });
        });

        on('settingsForm', 'submit', function (e) {
            submit(e, 'settingsResult', settingsPayload());
        });
    }

    document.addEventListener('DOMContentLoaded', init);
})();
