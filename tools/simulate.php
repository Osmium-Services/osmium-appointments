<?php

/**
 * Dev-only real-time simulation of a busy booking period: customers ring or use the website, the business confirms or
 * declines requests, some appointments are moved, and some customers cancel. It goes through AppointmentDesk, the same
 * code the admin screen and the website form use, and sends real emails (to Mailpit on a dev site).
 * Never run this on a live site.
 *
 * Usage (inside the php container, from the site's web root):
 *   php app/services/osmium-appointments/tools/simulate.php [--every=20] [--arrivals=48] [--fresh] [--seed]
 *
 *   --every=N     seconds between arrivals (default 20)
 *   --arrivals=N  how many to make (default 48), over tomorrow and the day after
 *   --fresh       delete all appointments first
 *   --seed        make 3 things to book (10 and 20 minute sunbeds, a 30 minute spray tan) and 3 booths if there are none,
 *                 and set the website rules to no notice
 *
 * Watch it on the admin Appointments page. Each event is also printed here and written to
 * app/cache/appointments-sim.log.
 */

declare(strict_types=1);

if (\php_sapi_name() !== 'cli') {
    echo "This script must be run from the command line.\n";
    exit(1);
}

\chdir(\dirname(__DIR__, 4));

require_once 'app/core/library/FileSystem.php';
require_once 'app/core/library/SqlLoader.php';
require_once 'app/core/library/OsmiumPDO.php';
require_once 'app/core/library/Autoload.php';

use Osmium\Core\Library\Autoload;
use Osmium\Core\Library\OsmiumPDO;
use Osmium\Core\Library\SqlLoader;
use Osmium\Services\OsmiumAppointments\Models\Appointment;
use Osmium\Services\OsmiumAppointments\Models\AppointmentConfig;
use Osmium\Services\OsmiumAppointments\Models\AppointmentDesk;
use Osmium\Services\OsmiumAppointments\Models\AppointmentResource;
use Osmium\Services\OsmiumAppointments\Models\AppointmentType;

(new Autoload('app/config'))->register();

$options = ['every' => 20, 'arrivals' => 48, 'fresh' => false, 'seed' => false];
foreach (\array_slice($argv, 1) as $arg) {
    if ($arg === '--fresh') $options['fresh'] = true;
    if ($arg === '--seed') $options['seed'] = true;
    if (\str_starts_with($arg, '--every=')) $options['every'] = \max(0, (int) \substr($arg, 8));
    if (\str_starts_with($arg, '--arrivals=')) $options['arrivals'] = \max(0, (int) \substr($arg, 11));
}

function readConfig(string $file): object
{
    $content = (string) \file_get_contents($file);

    return \json_decode(\substr($content, (int) \strpos($content, '{')), false);
}

$dbConfig = readConfig('app/config/database/db.json.php');
$config = readConfig('app/config/config.json.php');
$config->site->FQDN = $config->site->scheme . '://' . $config->site->domain;
$db = new OsmiumPDO($dbConfig, new SqlLoader());
$osmium = (object) ['config' => $config, 'dataSource' => $db];

$desk = new AppointmentDesk(osmium: $osmium);
$appointments = new Appointment($db);
$types = new AppointmentType($db);
$booths = new AppointmentResource($db);

$logFile = 'app/cache/appointments-sim.log';
function say(string $kind, string $text): void
{
    global $logFile;
    $line = \date('H:i:s') . '  ' . \str_pad($kind, 9) . $text;
    echo $line . "\n";
    \file_put_contents($logFile, $line . "\n", FILE_APPEND);
}
\file_put_contents($logFile, '');

if ($options['fresh']) {
    $db->query('DELETE FROM ' . $db->tablePrefix() . 'appointments');
    $db->execute();
    say('SETUP', 'all appointments deleted');
}

if ($options['seed']) {
    $noTypes = $types->listAll() === [];
    if ($noTypes) {
        foreach ([['Sunbed 10 min', 10], ['Sunbed 20 min', 20], ['Spray tan', 30]] as $n => [$name, $minutes]) {
            $types->create(['name' => $name, 'description' => null, 'durationMinutes' => $minutes, 'cleanMinutes' => 5, 'priceText' => null, 'enabled' => true, 'sortOrder' => $n + 1]);
        }
        say('SETUP', '3 things to book made');
    }

    $noBooths = $booths->listAll() === [];
    if ($noBooths) {
        foreach ([1, 2, 3] as $n) {
            $booths->create(name: 'Booth ' . $n, enabled: true, sortOrder: $n);
        }
        say('SETUP', '3 booths made');
    }

    AppointmentConfig::save(['minNoticeHours' => 0, 'maxDaysAhead' => 90] + AppointmentConfig::stored());
    say('SETUP', 'no notice needed on the website');
}

$typeRows = \array_values(\array_filter($types->listAll(), static fn(array $row): bool => (bool) $row['enabled']));
$noTypesToBook = $typeRows === [];
if ($noTypesToBook) {
    say('ERROR', 'nothing to book: run once with --seed');
    exit(1);
}

$days = [(new \DateTimeImmutable('+1 day'))->format('Y-m-d'), (new \DateTimeImmutable('+2 days'))->format('Y-m-d')];
$times = ['17:00', '17:15', '17:30', '18:00', '18:00', '18:15', '18:30', '19:00', '19:00', '19:30', '19:30', '20:00', '20:00', '20:30', '21:00', '21:30', '22:00'];
$names = \explode(' ', 'Adams Baker Clark Davies Ellis Foster Green Harris Ibrahim Jackson Kelly Lewis Morgan Nash Owen Price Quinn Reid Shaw Turner Usher Vance Walker Young Zane Allen Bell Cole Dean Fox Grant Hill Jones King Law Mills Page Ross Stone Wood Bishop Carter Dixon Evans Flynn Gray Hunt Ives Khan Long');
$pick = static fn(array $list): mixed => $list[\array_rand($list)];
$chance = static fn(int $percent): bool => \random_int(1, 100) <= $percent;
$short = static fn(array $r): string => $r['customer_name'] . ', ' . $r['type_name'] . ' at ' . \substr($r['starts_at'], 11, 5) . ' on ' . \substr($r['starts_at'], 8, 2) . '/' . \substr($r['starts_at'], 5, 2);
$later = static fn(string $time, int $minutes): string => \date('H:i', \strtotime('2000-01-01 ' . $time . ' +' . $minutes . ' minutes'));

/** A caller on the phone: the business tries the time asked for; if it is taken they try half an hour later */
$phoneCall = function (array $a) use ($desk, $chance, $later): void {
    $input = ['name' => $a['name'], 'email' => \strtolower($a['name']) . '@example.com', 'phone' => '07700900' . \str_pad((string) \random_int(100, 999), 3, '0'), 'type_id' => $a['typeId'], 'resource_id' => 0, 'date' => $a['date'], 'time' => $a['time'], 'notes' => ''];
    $what = $a['name'] . ', ' . $a['typeName'] . ' at ' . $a['time'] . ' on ' . \substr($a['date'], 8) . '/' . \substr($a['date'], 5, 2);

    try {
        $desk->createByStaff($input);
        say('PHONE', $what . ' -> booked');
    } catch (\InvalidArgumentException $first) {
        $giveUp = $chance(30);
        if ($giveUp) {
            say('PHONE', $what . ' -> ' . $first->getMessage() . ' (caller gave up)');

            return;
        }

        $time = $later($a['time'], 30);
        try {
            $desk->createByStaff(['time' => $time] + $input);
            say('PHONE', $what . ' -> not free, caller took ' . $time . ' instead');
        } catch (\InvalidArgumentException $second) {
            say('PHONE', $what . ' -> ' . $second->getMessage() . ' (lost)');
        }
    }
};

/** A customer on the website: the request waits for the business to answer it */
$webRequest = function (array $a) use ($desk): void {
    $what = $a['name'] . ', ' . $a['typeName'] . ' at ' . $a['time'] . ' on ' . \substr($a['date'], 8) . '/' . \substr($a['date'], 5, 2);
    $request = ['typeId' => $a['typeId'], 'resourceId' => null, 'date' => $a['date'], 'time' => $a['time'], 'name' => $a['name'], 'email' => \strtolower($a['name']) . '@example.com', 'phone' => null, 'notes' => null];

    try {
        $desk->acceptRequest($request);
        say('WEB', $what . ' -> request sent, waiting for the business');
    } catch (\InvalidArgumentException $e) {
        say('WEB', $what . ' -> refused on the page: ' . $e->getMessage());
    }
};

/** The business works through the waiting requests: most are confirmed, some declined, a few left for later */
$answers = function () use ($appointments, $desk, $short): void {
    foreach ($appointments->listAll() as $r) {
        $waiting = $r['status'] === 'requested';
        if (!$waiting) continue;

        $roll = \random_int(1, 100);
        if ($roll > 80) continue;

        if ($roll > 70) {
            $desk->decline(id: (int) $r['id'], message: '');
            say('ANSWER', $short($r) . ' -> declined');
            continue;
        }

        try {
            $desk->confirm((int) $r['id']);
            say('ANSWER', $short($r) . ' -> confirmed');
        } catch (\InvalidArgumentException $e) {
            $desk->decline(id: (int) $r['id'], message: '');
            say('ANSWER', $short($r) . ' -> clashed, declined');
        }
    }
};

/** Someone rings to move a confirmed appointment half an hour later */
$move = function () use ($appointments, $desk, $pick, $short, $later): void {
    $open = \array_values(\array_filter($appointments->listAll(), static fn(array $r): bool => $r['status'] === 'confirmed'));
    if ($open === []) return;

    $r = $pick($open);
    $time = $later(\substr($r['starts_at'], 11, 5), 30);
    try {
        $desk->reschedule(id: (int) $r['id'], input: ['type_id' => $r['type_id'], 'resource_id' => (int) ($r['resource_id'] ?? 0), 'date' => \substr($r['starts_at'], 0, 10), 'time' => $time, 'admin_notes' => '']);
        say('MOVE', $short($r) . ' -> moved to ' . $time);
    } catch (\InvalidArgumentException $e) {
        say('MOVE', $short($r) . ' -> could not move: ' . $e->getMessage());
    }
};

/** Someone cancels */
$cancellation = function () use ($appointments, $desk, $pick, $short): void {
    $open = \array_values(\array_filter($appointments->listAll(), static fn(array $r): bool => $r['status'] === 'confirmed'));
    if ($open === []) return;

    $r = $pick($open);
    $desk->cancel(id: (int) $r['id'], message: '');
    say('CANCEL', $short($r) . ' -> cancels');
};

say('START', $options['arrivals'] . ' arrivals, one every ' . $options['every'] . 's, for ' . $days[0] . ' and ' . $days[1]);
for ($i = 1; $i <= $options['arrivals']; $i++) {
    if ($i > 1) \sleep($options['every']);

    $type = $pick($typeRows);
    $a = ['name' => $names[($i - 1) % \count($names)] . ($i > \count($names) ? (string) $i : ''), 'typeId' => (int) $type['id'], 'typeName' => $type['name'], 'time' => $pick($times), 'date' => $pick($days)];
    try {
        $isWeb = $chance(50);
        if ($isWeb) $webRequest($a);
        if (!$isWeb) $phoneCall($a);

        $answersDue = $i % 2 === 0;
        if ($answersDue) $answers();

        $someoneMoves = $i >= 5 && $i % 5 === 0;
        if ($someoneMoves) $move();

        $someoneCancels = $i >= 6 && $i % 4 === 0;
        if ($someoneCancels) $cancellation();
    } catch (\Throwable $e) {
        say('ERROR', $e->getMessage());
    }
}

foreach ($days as $day) {
    $counts = ['confirmed' => 0, 'requested' => 0, 'cancelled' => 0, 'declined' => 0];
    foreach ($appointments->listAll() as $r) {
        $isThisDay = \substr($r['starts_at'], 0, 10) === $day;
        if (!$isThisDay) continue;

        $counts[$r['status']]++;
    }
    say('SUMMARY', $day . ': ' . $counts['confirmed'] . ' confirmed, ' . $counts['requested'] . ' still waiting, ' . $counts['cancelled'] . ' cancelled, ' . $counts['declined'] . ' declined');
}
say('DONE', 'finished');
