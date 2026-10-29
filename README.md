# Osmium Appointments

Appointment requests for businesses that serve **one customer at a time per person or booth** for a set length of time: hairdressers, tanning salons, dentists, physios, driving instructors, vets. It is not for restaurants, classes or tours (shared capacity), or for hiring equipment or rooms over several days; those need their own services. A customer asks for a time, you confirm or decline, and they are emailed each step. Appointments are stored locally; other services (for example a calendar sync) can react to the `appointment.*` hooks.

## Day to day (admin > Appointments)

A **Schedule**: a **Day** view with a column for each person, room or item (or one column if there are none), or a **Week** view for one of them with a column for each day: waiting requests and confirmed appointments as blocks at their time and length, breaks, closed hours and times people do not work shaded, and day arrows. Click a block to see who it is, then confirm or decline a request, move or reassign it, or cancel a confirmed one (each emails the customer). Below it, the list of appointments with the Deleted bin.

## Setup (admin > Services > Osmium Appointments)

- **What can be booked**: name, length in minutes, optional price text (shown only, no payment).
- **Who or what is booked**: optional people, rooms or equipment, each with an optional daily break (a person's lunch; stagger them so the shop stays open), regular hours that narrow the business's (for example 09:00 to 15:00, or a day off), and time off (holiday, sickness; whole days or part days). None of these can be booked, they are greyed on the Schedule, and bookings made before the time off are left for you to move or cancel. Clashes are checked per one. A "no preference" request is given to the first person who is free and not on break, spreading waiting requests across the team; with no people set up, the business is one lane. When the time asked for is taken the customer is told who else is free then and the nearest earlier and later times that would work that day.
- **Clean-up time**: each type can hold its person or booth for some minutes after it (a 20 minute sunbed session with 10 minutes to clean). Nothing else can be booked into that time, it shows hatched on the Schedule, and customers never see it (their email, calendar file and form show the session length only). Use it with a time step that matches the cycle (for example 30).
- **Appointment rules**: opening hours come from Company > Location and are the most that can be booked. For each weekday you can set a narrower bookable window (for example to keep the first and last half hour free) and an optional break (for example lunch). Also minimum notice, how far ahead, time step, and who is emailed.

## Theme

```php
$appointments = new \Osmium\Services\OsmiumAppointments\Models\Appointments($this->osmium->dataSource);
$appointments->types(); $appointments->resources(); $appointments->rules(); $appointments->pullNotice($_SESSION);
```

POST the form to `/api/appointments` with `token`, honeypot `zip`, `type_id`, optional `resource_id`, `name`, `email`, optional `phone` and `notes`, `date` (Y-m-d) and `time` (H:i).

## Developing

Every action (website request, confirm, decline, cancel, phone booking, move) goes through `models/AppointmentDesk.php`, which the admin screen, the website form and the dev simulator all share. `tools/simulate.php` plays a busy two days of calls, website requests, answers, moves and cancellations on a dev site (`php app/services/osmium-appointments/tools/simulate.php --seed --every=2 --arrivals=24`). It sends real emails and must never be run on a live site.

## Hooks

`appointment.requested`, `appointment.confirmed`, `appointment.changed`, `appointment.declined`, `appointment.cancelled`. Payload: `appointment`, `appointmentId`, `dataSource`, `osmium`. A service may store its own reference in the appointment's `external_refs`.

## Not included

Live availability, deposits, reminders, attachments (emails carry Google Calendar and `.ics` links), party size or capacity (one appointment per time per person or room), and appointments that span more than one day (hire, stays).
