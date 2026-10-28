# Osmium Bookings

Appointment requests for any kind of business. A customer asks for a time, you confirm or decline, and they are emailed each step. Bookings are stored locally; other services (for example a calendar sync) can react to the `booking.*` hooks.

## Setup (admin > Osmium Bookings)

- **What can be booked**: name, length in minutes, optional price text (shown only, no payment).
- **Who or what is booked**: optional people, rooms or equipment. Clashes are checked per one; with none, the business is one lane.
- **Booking rules**: opening hours come from Company > Location and are the most that can be booked. For each weekday you can set a narrower bookable window (for example to keep the first and last half hour free) and an optional break (for example lunch). Also minimum notice, how far ahead, time step, and who is emailed.

## Theme

```php
$bookings = new \Osmium\Services\OsmiumBookings\Models\Bookings($this->osmium->dataSource);
$bookings->types(); $bookings->resources(); $bookings->rules(); $bookings->pullNotice($_SESSION);
```

POST the form to `/api/bookings` with `token`, honeypot `zip`, `type_id`, optional `resource_id`, `name`, `email`, optional `phone` and `notes`, `date` (Y-m-d) and `time` (H:i).

## Hooks

`booking.requested`, `booking.confirmed`, `booking.changed`, `booking.declined`, `booking.cancelled`. Payload: `booking`, `bookingId`, `dataSource`, `osmium`. A service may store its own reference in the booking's `external_refs`.

## Not included

Live availability, deposits, reminders, attachments (emails carry Google Calendar and `.ics` links), party size or capacity (one booking per time per person or room), and bookings that span more than one day (hire, stays).
