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

    const DECISIONS = {
        confirm: { button: 'appointmentConfirmButton', label: 'Confirm', statuses: ['requested'], sure: false },
        decline: { button: 'appointmentDeclineButton', label: 'Decline request', statuses: ['requested'], sure: true },
        cancel: { button: 'appointmentCancelButton', label: 'Cancel appointment', statuses: ['confirmed'], sure: true }
    };
    let pendingDecision = null;
    let pendingTimer = null;

    function resetDecisionButtons(status) {
        pendingDecision = null;
        window.clearTimeout(pendingTimer);
        byId('appointmentDeclineBox').classList.add('d-none');
        Object.keys(DECISIONS).forEach(function (name) {
            const decision = DECISIONS[name];
            const button = byId(decision.button);
            button.textContent = decision.label;
            button.classList.toggle('d-none', decision.statuses.indexOf(status) === -1);
            button.disabled = false;
        });

        // Waiting for an answer, Confirm is the main action; once confirmed, saving changes is
        const save = byId('appointmentSaveButton');
        save.classList.toggle('btn-primary', status !== 'requested');
        save.classList.toggle('btn-outline-primary', status === 'requested');
    }

    // Decline and Cancel email the customer, so the first click asks "sure?" and the second one does it
    async function decide(name) {
        const decision = DECISIONS[name];
        const button = byId(decision.button);

        if (decision.sure && pendingDecision !== name) {
            resetDecisionButtons(currentStatus);
            pendingDecision = name;
            button.textContent = 'Click again to ' + decision.label.toLowerCase();
            byId('appointmentDeclineBox').classList.toggle('d-none', name === 'confirm');
            // Declining or cancelling may need a few words for the customer, so give time to type them
            const waitMs = 120000;
            pendingTimer = window.setTimeout(function () { resetDecisionButtons(currentStatus); }, waitMs);
            return;
        }

        clear('appointmentResult');
        button.disabled = true;
        try {
            const payload = { action: name, id: byId('appointmentId').value };
            if (name !== 'confirm') payload.message = byId('appointmentDeclineMessage').value;
            const result = await post(payload);
            if (result.success) {
                window.location.reload();
                return;
            }

            show('appointmentResult', 'danger', result.error || 'That did not work');
        } catch (error) {
            show('appointmentResult', 'danger', 'An error occurred');
        }
        resetDecisionButtons(currentStatus);
    }

    let currentStatus = '';

    // A booking taken by phone or in person: the form opens empty, on the day being looked at
    function openNewAppointment(dateText) {
        clear('appointmentResult');
        byId('appointmentForm').reset();
        byId('appointmentId').value = '';
        currentStatus = '';
        resetDecisionButtons('');
        byId('appointmentModalTitle').textContent = 'New appointment';
        byId('appointmentNewFields').classList.remove('d-none');
        byId('appointmentCustomer').classList.add('d-none');
        byId('appointmentWho').classList.add('d-none');
        byId('appointmentTheirNotes').textContent = '';
        byId('appointmentNotesLabel').textContent = 'Notes (kept on the booking)';
        byId('appointmentSaveButton').textContent = 'Book it';
        byId('appointmentSaveButton').classList.add('btn-primary');
        byId('appointmentSaveButton').classList.remove('btn-outline-primary');
        byId('appointmentResource').value = '0';
        byId('appointmentDate').value = dateText;
        openModal('appointmentModal');
    }

    function openAppointment(appointment) {
        clear('appointmentResult');
        byId('appointmentModalTitle').textContent = 'Edit appointment';
        byId('appointmentNewFields').classList.add('d-none');
        byId('appointmentCustomer').classList.remove('d-none');
        byId('appointmentWho').classList.remove('d-none');
        byId('appointmentNotesLabel').textContent = 'Your notes (never shown to the customer)';
        byId('appointmentSaveButton').textContent = 'Save changes';
        currentStatus = appointment.status;
        resetDecisionButtons(appointment.status);
        byId('appointmentId').value = appointment.id;
        byId('appointmentCustomer').textContent = [appointment.customerName, appointment.customerEmail, appointment.customerPhone].filter(Boolean).join(' · ');
        byId('appointmentWho').textContent = appointment.reference + ' · ' + (appointment.status === 'requested' ? 'waiting for you' : appointment.status);
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

    // What the schedule shows: a day (a column for each person) or a week (a column for each day, for one person)
    const scheduleState = { mode: 'day', person: '', date: '' };

    function weekdayOf(date) {
        return (date.getDay() + 6) % 7 + 1;
    }

    function activeOn(dateText) {
        return Object.values(config.appointments).filter(function (item) {
            return item.date === dateText && (item.status === 'requested' || item.status === 'confirmed');
        });
    }

    function enabledPeople() {
        return Object.values(config.resources).filter(function (person) { return person.enabled; });
    }

    /**
     * The columns for the date shown. Each has a title, its date, which appointments belong (a person's id, '' for
     * "no one in particular", or null for all of them) and that person's daily break.
     */
    function scheduleColumns(dateText) {
        const date = new Date(dateText + 'T00:00:00');
        const people = enabledPeople();

        if (scheduleState.mode === 'week') {
            const person = people.filter(function (candidate) { return String(candidate.id) === scheduleState.person; })[0] || null;
            const monday = new Date(date);
            monday.setDate(date.getDate() - (weekdayOf(date) - 1));

            return Array.from({ length: 7 }, function (unused, offset) {
                const day = new Date(monday);
                day.setDate(monday.getDate() + offset);

                return {
                    title: day.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' }),
                    date: isoDate(day),
                    person: person ? String(person.id) : null,
                    breakStart: person ? person.breakStart : '',
                    breakEnd: person ? person.breakEnd : ''
                };
            });
        }

        const columns = people.map(function (person) {
            return { title: person.name, date: dateText, person: String(person.id), breakStart: person.breakStart, breakEnd: person.breakEnd };
        });
        const unassigned = activeOn(dateText).some(function (item) { return item.resourceId === ''; });
        if (unassigned || columns.length === 0) {
            columns.push({ title: columns.length === 0 ? 'Appointments' : 'No one in particular', date: dateText, person: columns.length === 0 ? null : '', breakStart: '', breakEnd: '' });
        }

        return columns;
    }

    function itemsFor(column) {
        return activeOn(column.date).filter(function (item) {
            return column.person === null || String(item.resourceId) === column.person;
        });
    }

    function renderSchedule(dateText) {
        const grid = byId('scheduleGrid');
        const columns = scheduleColumns(dateText);
        grid.textContent = '';

        columns.forEach(function (column) {
            column.hours = config.schedule.hours[weekdayOf(new Date(column.date + 'T00:00:00'))] || null;
            column.items = itemsFor(column);
        });

        const nothingToShow = columns.every(function (column) { return column.hours === null && column.items.length === 0; });
        if (nothingToShow) {
            grid.appendChild(el('div', 'sched-note')).textContent = scheduleState.mode === 'week' ? 'Closed this week.' : 'Closed this day.';
            return;
        }

        // The visible hours: opening time, widened to take in anything already booked outside it
        let first = 24 * 60;
        let last = 0;
        columns.forEach(function (column) {
            if (column.hours) {
                first = Math.min(first, toMinutes(column.hours.open));
                last = Math.max(last, toMinutes(column.hours.close));
            }
            column.items.forEach(function (item) {
                first = Math.min(first, toMinutes(item.time));
                last = Math.max(last, toMinutes(item.endTime));
            });
        });
        const origin = Math.floor(first / 60) * 60;
        const end = Math.ceil(last / 60) * 60;
        const height = (end - origin) * PX_PER_MINUTE;

        const table = el('div', 'sched', { gridTemplateColumns: '3.5rem repeat(' + columns.length + ', minmax(7rem, 1fr))' });
        table.appendChild(el('div', 'sched-head'));
        columns.forEach(function (column) { table.appendChild(el('div', 'sched-head')).textContent = column.title; });

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

            if (column.hours === null) {
                shade(lane, 'sched-closed', origin, end, origin);
            } else {
                shade(lane, 'sched-closed', origin, toMinutes(column.hours.open), origin);
                shade(lane, 'sched-closed', toMinutes(column.hours.close), end, origin);
                if (column.hours.breakStart) shade(lane, 'sched-break', toMinutes(column.hours.breakStart), toMinutes(column.hours.breakEnd), origin);
            }
            if (column.breakStart && column.hours !== null) shade(lane, 'sched-break', toMinutes(column.breakStart), toMinutes(column.breakEnd), origin);

            column.items.forEach(function (item) {
                const block = el('button', 'sched-appt sched-' + item.status, {
                    top: (toMinutes(item.time) - origin) * PX_PER_MINUTE + 'px',
                    height: Math.max(18, (toMinutes(item.endTime) - toMinutes(item.time)) * PX_PER_MINUTE - 2) + 'px'
                });
                block.type = 'button';
                block.title = item.time + ' to ' + item.endTime + ' ' + item.customerName + ' (' + item.status + ')' + (item.typeName ? ', ' + item.typeName : '');
                block.appendChild(document.createTextNode(item.time + ' ' + item.customerName));
                block.appendChild(el('small')).textContent = item.typeName;
                block.addEventListener('click', function () { openAppointment(item); });
                lane.appendChild(block);
            });

            table.appendChild(lane);
        });

        grid.appendChild(table);
    }

    function rememberView() {
        try {
            window.localStorage.setItem('osmiumAppointmentsView', JSON.stringify(Object.assign({}, scheduleState, { date: byId('scheduleDate').value })));
        } catch (error) {
            // Private windows can refuse storage; the view just is not remembered
        }
    }

    function restoreView() {
        try {
            const saved = JSON.parse(window.localStorage.getItem('osmiumAppointmentsView') || 'null');
            if (saved && (saved.mode === 'day' || saved.mode === 'week')) {
                scheduleState.mode = saved.mode;
                scheduleState.person = String(saved.person || '');
                scheduleState.date = /^\d{4}-\d\d-\d\d$/.test(saved.date || '') ? saved.date : '';
            }
        } catch (error) {
            // Nothing remembered: start on the day view
        }
    }

    function initSchedule() {
        const input = byId('scheduleDate');
        const select = byId('schedulePerson');
        const people = enabledPeople();

        restoreView();
        const known = people.some(function (person) { return String(person.id) === scheduleState.person; });
        if (!known) scheduleState.person = people.length > 0 ? String(people[0].id) : '';

        people.forEach(function (person) {
            const option = document.createElement('option');
            option.value = String(person.id);
            option.textContent = person.name;
            select.appendChild(option);
        });

        const draw = function () {
            const weekMode = scheduleState.mode === 'week';
            byId('scheduleModeDay').classList.toggle('active', !weekMode);
            byId('scheduleModeWeek').classList.toggle('active', weekMode);
            select.classList.toggle('d-none', !weekMode || people.length === 0);
            select.value = scheduleState.person;
            byId('schedulePrev').setAttribute('aria-label', weekMode ? 'Previous week' : 'Previous day');
            byId('scheduleNext').setAttribute('aria-label', weekMode ? 'Next week' : 'Next day');
            rememberView();
            if (input.value) renderSchedule(input.value);
        };
        const show = function (date) {
            input.value = isoDate(date);
            draw();
        };
        const shift = function (direction) {
            const date = new Date(input.value + 'T00:00:00');
            date.setDate(date.getDate() + direction * (scheduleState.mode === 'week' ? 7 : 1));
            show(date);
        };

        on('scheduleToday', 'click', function () { show(new Date()); });
        on('scheduleNew', 'click', function () { openNewAppointment(input.value || isoDate(new Date())); });
        on('schedulePrev', 'click', function () { shift(-1); });
        on('scheduleNext', 'click', function () { shift(1); });
        on('scheduleModeDay', 'click', function () { scheduleState.mode = 'day'; draw(); });
        on('scheduleModeWeek', 'click', function () { scheduleState.mode = 'week'; draw(); });
        select.addEventListener('change', function () { scheduleState.person = select.value; draw(); });
        input.addEventListener('change', function () { if (input.value) draw(); });
        show(scheduleState.date ? new Date(scheduleState.date + 'T00:00:00') : new Date());
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

        Object.keys(DECISIONS).forEach(function (name) {
            on(DECISIONS[name].button, 'click', function () { decide(name); });
        });

        on('appointmentForm', 'submit', function (e) {
            const creating = byId('appointmentId').value === '';
            submit(e, 'appointmentResult', creating ? {
                action: 'create',
                name: byId('appointmentName').value,
                phone: byId('appointmentPhone').value,
                email: byId('appointmentEmail').value,
                type_id: byId('appointmentType').value,
                resource_id: byId('appointmentResource').value,
                date: byId('appointmentDate').value,
                time: byId('appointmentTime').value,
                notes: byId('appointmentNotes').value
            } : {
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
