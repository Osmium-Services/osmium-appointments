<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumAppointments\Models;

use Osmium\Core\Library\MailerFactory;
use Osmium\Core\Library\ServiceHooks;
use Osmium\Core\Library\ServiceLoader;
use Osmium\Core\Models\ServiceModel;

/**
 * Tells the customer (and the business) what happened to an appointment, and announces it to other services with a hook
 * (appointment.requested, .confirmed, .changed, .declined, .cancelled). A mail that fails to send is logged and never
 * stops the appointment; a service that fails on a hook is logged by core and skipped.
 */
class AppointmentNotifier
{
    public function __construct(private object $osmium)
    {
    }

    public function requested(array $appointment): void
    {
        $siteName = $this->siteName();
        $this->send(to: $appointment['customer_email'], mail: AppointmentMail::received($appointment, $siteName));

        $adminUrl = $this->osmium->config->site->FQDN . '/' . $this->osmium->config->site->adminPath . '/appointments' . $this->slash();
        $team = AppointmentMail::requestForTeam(appointment: $appointment, siteName: $siteName, adminUrl: $adminUrl);
        foreach (AppointmentConfig::get()['notifyEmails'] as $email) {
            $this->send(to: $email, mail: $team);
        }

        $this->announce(event: 'appointment.requested', appointment: $appointment);
    }

    public function confirmed(array $appointment): void
    {
        $this->send(to: $appointment['customer_email'], mail: AppointmentMail::confirmed($appointment, $this->siteName(), $this->icsUrl($appointment), $this->googleUrl($appointment)));
        $this->announce(event: 'appointment.confirmed', appointment: $appointment);
    }

    public function changed(array $appointment): void
    {
        $this->send(to: $appointment['customer_email'], mail: AppointmentMail::changed($appointment, $this->siteName(), $this->icsUrl($appointment), $this->googleUrl($appointment)));
        $this->announce(event: 'appointment.changed', appointment: $appointment);
    }

    public function declined(array $appointment): void
    {
        $this->send(to: $appointment['customer_email'], mail: AppointmentMail::declined($appointment, $this->siteName()));
        $this->announce(event: 'appointment.declined', appointment: $appointment);
    }

    public function cancelled(array $appointment): void
    {
        $this->send(to: $appointment['customer_email'], mail: AppointmentMail::cancelled($appointment, $this->siteName()));
        $this->announce(event: 'appointment.cancelled', appointment: $appointment);
    }

    public function icsUrl(array $appointment): string
    {
        return $this->osmium->config->site->FQDN . '/api/appointments-calendar' . $this->slash() . '?ref=' . \rawurlencode((string) $appointment['reference']);
    }

    private function googleUrl(array $appointment): string
    {
        $siteName = $this->siteName();

        return AppointmentIcs::googleLink(
            appointment: $appointment,
            summary: AppointmentMail::eventTitle($appointment, $siteName),
            description: 'Appointment reference ' . $appointment['reference'],
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
            if ($failed) \error_log('Appointments: mail to ' . $to . ' failed: ' . ($result['error'] ?? 'unknown error'));
        } catch (\Throwable $e) {
            \error_log('Appointments: mail to ' . $to . ' failed: ' . $e->getMessage());
        }
    }

    private function announce(string $event, array $appointment): void
    {
        $hooks = new ServiceHooks(new ServiceLoader(new ServiceModel($this->osmium->dataSource)));
        $hooks->dispatch($event, ['appointment' => $appointment, 'appointmentId' => (int) $appointment['id'], 'dataSource' => $this->osmium->dataSource, 'osmium' => $this->osmium]);
    }
}
