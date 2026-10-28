<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumBookings\Models;

use Osmium\Core\Library\MailerFactory;
use Osmium\Core\Library\ServiceHooks;
use Osmium\Core\Library\ServiceLoader;
use Osmium\Core\Models\ServiceModel;

/**
 * Tells the customer (and the business) what happened to a booking, and announces it to other services with a hook
 * (booking.requested, .confirmed, .changed, .declined, .cancelled). A mail that fails to send is logged and never
 * stops the booking; a service that fails on a hook is logged by core and skipped.
 */
class BookingNotifier
{
    public function __construct(private object $osmium)
    {
    }

    public function requested(array $booking): void
    {
        $siteName = $this->siteName();
        $this->send(to: $booking['customer_email'], mail: BookingMail::received($booking, $siteName));

        $adminUrl = $this->osmium->config->site->FQDN . '/' . $this->osmium->config->site->adminPath . '/bookings' . $this->slash();
        $team = BookingMail::requestForTeam(booking: $booking, siteName: $siteName, adminUrl: $adminUrl);
        foreach (BookingConfig::get()['notifyEmails'] as $email) {
            $this->send(to: $email, mail: $team);
        }

        $this->announce(event: 'booking.requested', booking: $booking);
    }

    public function confirmed(array $booking): void
    {
        $this->send(to: $booking['customer_email'], mail: BookingMail::confirmed($booking, $this->siteName(), $this->icsUrl($booking), $this->googleUrl($booking)));
        $this->announce(event: 'booking.confirmed', booking: $booking);
    }

    public function changed(array $booking): void
    {
        $this->send(to: $booking['customer_email'], mail: BookingMail::changed($booking, $this->siteName(), $this->icsUrl($booking), $this->googleUrl($booking)));
        $this->announce(event: 'booking.changed', booking: $booking);
    }

    public function declined(array $booking): void
    {
        $this->send(to: $booking['customer_email'], mail: BookingMail::declined($booking, $this->siteName()));
        $this->announce(event: 'booking.declined', booking: $booking);
    }

    public function cancelled(array $booking): void
    {
        $this->send(to: $booking['customer_email'], mail: BookingMail::cancelled($booking, $this->siteName()));
        $this->announce(event: 'booking.cancelled', booking: $booking);
    }

    public function icsUrl(array $booking): string
    {
        return $this->osmium->config->site->FQDN . '/api/bookings-calendar' . $this->slash() . '?ref=' . \rawurlencode((string) $booking['reference']);
    }

    private function googleUrl(array $booking): string
    {
        $siteName = $this->siteName();

        return BookingIcs::googleLink(
            booking: $booking,
            summary: BookingMail::eventTitle($booking, $siteName),
            description: 'Booking reference ' . $booking['reference'],
            zone: new \DateTimeZone(\date_default_timezone_get()),
        );
    }

    /** The site's own habit for the end of a URL: the web server redirects the other form */
    private function slash(): string
    {
        return ($this->osmium->config->site->urlTrailingSlash ?? false) ? '/' : '';
    }

    private function siteName(): string
    {
        return (string) $this->osmium->config->site->name;
    }

    /**
     * @param array{subject: string, html: string} $mail
     */
    private function send(string $to, array $mail): void
    {
        try {
            $result = MailerFactory::make($this->osmium->config)->send(
                recipients: [['email' => $to]],
                subject: $mail['subject'],
                htmlBody: $mail['html'],
            );
            $failed = empty($result['success']);
            if ($failed) \error_log('Bookings: mail to ' . $to . ' failed: ' . ($result['error'] ?? 'unknown error'));
        } catch (\Throwable $e) {
            \error_log('Bookings: mail to ' . $to . ' failed: ' . $e->getMessage());
        }
    }

    private function announce(string $event, array $booking): void
    {
        $hooks = new ServiceHooks(new ServiceLoader(new ServiceModel($this->osmium->dataSource)));
        $hooks->dispatch($event, ['booking' => $booking, 'bookingId' => (int) $booking['id'], 'dataSource' => $this->osmium->dataSource, 'osmium' => $this->osmium]);
    }
}
