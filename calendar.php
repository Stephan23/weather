<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Berlin');

/* ============================================
 * Konfiguration
 * ============================================ */

$configFile = __DIR__ . '/calendar.config.php';

if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Konfigurationsdatei fehlt',
        'hint' => 'Bitte calendar.config.example.php nach calendar.config.php kopieren und anpassen.'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$config = require $configFile;

$server = $config['server'] ?? '';
$user = $config['user'] ?? '';
$password = $config['password'] ?? '';
$calendars = $config['calendars'] ?? [];
$timezoneName = $config['timezone'] ?? 'Europe/Berlin';
$days = (int)($config['days'] ?? 8);

date_default_timezone_set($timezoneName);

$timezone = new DateTimeZone($timezoneName);
$rangeStart = new DateTimeImmutable('today', $timezone);
$rangeEnd = $rangeStart->modify('+' . $days . ' days');

function outputError($message, $extra = []) {
    http_response_code(502);
    echo json_encode(array_merge(['error' => $message], $extra),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

function unfoldIcs($ics) {
    // iCalendar-Zeilen, die mit Leerzeichen/Tab beginnen, gehören zur Vorzeile.
    $ics = str_replace(["\r\n", "\r"], "\n", $ics);
    return preg_replace("/\n[ \t]/", '', $ics);
}

function parsePropertyLine($line) {
    $pos = strpos($line, ':');
    if ($pos === false) return null;

    $left = substr($line, 0, $pos);
    $value = substr($line, $pos + 1);
    $parts = explode(';', $left);
    $name = strtoupper(array_shift($parts));
    $params = [];

    foreach ($parts as $part) {
        $p = explode('=', $part, 2);
        if (count($p) === 2) {
            $params[strtoupper($p[0])] = trim($p[1], '"');
        }
    }

    return ['name' => $name, 'params' => $params, 'value' => $value];
}

function unescapeIcsText($value) {
    return str_replace(
        ['\\n', '\\N', '\\,', '\\;', '\\\\'],
        ["\n", "\n", ',', ';', '\\'],
        $value
    );
}

function parseIcsDate($property, $fallbackTimezone) {
    if (!$property) return null;

    $value = trim($property['value']);
    $params = $property['params'];
    $isDate = (($params['VALUE'] ?? '') === 'DATE') || preg_match('/^\d{8}$/', $value);

    $tz = $fallbackTimezone;
    if (!empty($params['TZID'])) {
        try { $tz = new DateTimeZone($params['TZID']); }
        catch (Exception $e) { $tz = $fallbackTimezone; }
    }

    if ($isDate) {
        $dt = DateTimeImmutable::createFromFormat('!Ymd', $value, $tz);
        return $dt ? ['date' => $dt, 'allDay' => true] : null;
    }

    if (substr($value, -1) === 'Z') {
        $dt = DateTimeImmutable::createFromFormat('!Ymd\THis\Z', $value, new DateTimeZone('UTC'));
        if ($dt) $dt = $dt->setTimezone($fallbackTimezone);
    } else {
        $dt = DateTimeImmutable::createFromFormat('!Ymd\THis', $value, $tz);
    }

    return $dt ? ['date' => $dt, 'allDay' => false] : null;
}

function parseRule($value) {
    $rule = [];
    foreach (explode(';', (string)$value) as $part) {
        $p = explode('=', $part, 2);
        if (count($p) === 2) $rule[strtoupper($p[0])] = strtoupper($p[1]);
    }
    return $rule;
}

function recurrenceDates($start, $rule, $rangeStart, $rangeEnd) {
    // Liefert Vorkommen als DateTimeImmutable. Für diesen SmartMirror werden
    // die üblichen einfachen Wiederholungen unterstützt.
    $freq = $rule['FREQ'] ?? '';
    $interval = max(1, (int)($rule['INTERVAL'] ?? 1));
    $countLimit = isset($rule['COUNT']) ? max(0, (int)$rule['COUNT']) : PHP_INT_MAX;
    $until = null;

    if (!empty($rule['UNTIL'])) {
        $u = $rule['UNTIL'];
        if (preg_match('/^\d{8}$/', $u)) {
            $until = DateTimeImmutable::createFromFormat('!Ymd', $u, $start->getTimezone());
        } else {
            $utc = substr($u, -1) === 'Z';
            $until = DateTimeImmutable::createFromFormat(
                $utc ? '!Ymd\THis\Z' : '!Ymd\THis',
                rtrim($u, 'Z'),
                $utc ? new DateTimeZone('UTC') : $start->getTimezone()
            );
            if ($until && $utc) $until = $until->setTimezone($start->getTimezone());
        }
    }

    $out = [];
    $add = function($candidate, $index) use (&$out, $countLimit, $until, $rangeStart, $rangeEnd) {
        if ($index >= $countLimit) return false;
        if ($until && $candidate > $until) return false;
        if ($candidate >= $rangeStart && $candidate < $rangeEnd) $out[] = $candidate;
        return true;
    };

    if ($freq === 'YEARLY') {
        // Jährliche Termine: ursprünglichen Monat und Tag in jedem Folgejahr verwenden.
        $firstYear = (int)$start->format('Y');
        $lastYear = (int)$rangeEnd->format('Y');
        $index = 0;

        for ($year = $firstYear; $year <= $lastYear + 1; $year++) {
            if ((($year - $firstYear) % $interval) !== 0) continue;

            $month = (int)$start->format('m');
            $day = (int)$start->format('d');
            if (!checkdate($month, $day, $year)) {
                // z.B. 29. Februar: in Nicht-Schaltjahren kein Vorkommen.
                continue;
            }

            $candidate = $start->setDate($year, $month, $day);
            if ($candidate < $start) continue;
            if (!$add($candidate, $index)) break;
            $index++;
        }
        return $out;
    }

    $step = [
        'DAILY' => "P{$interval}D",
        'WEEKLY' => "P" . (7 * $interval) . "D",
        'MONTHLY' => "P{$interval}M"
    ][$freq] ?? null;

    if (!$step) return [$start >= $rangeStart && $start < $rangeEnd ? $start : null];

    // Bei alten täglichen/wöchentlichen Regeln COUNT korrekt ab DTSTART zählen.
    $candidate = $start;
    $index = 0;
    $maxIterations = 50000;

    while ($candidate < $rangeEnd && $index < $maxIterations) {
        if (!$add($candidate, $index)) break;
        $candidate = $candidate->add(new DateInterval($step));
        $index++;
    }

    return $out;
}

function parseEvent($ics, $calendar, $rangeStart, $rangeEnd, $timezone) {
    $ics = unfoldIcs($ics);
    preg_match_all('/BEGIN:VEVENT\n(.*?)\nEND:VEVENT/s', $ics, $matches);
    $events = [];

    foreach ($matches[1] as $block) {
        $props = [];
        foreach (explode("\n", $block) as $line) {
            $p = parsePropertyLine(trim($line));
            if ($p) $props[$p['name']][] = $p;
        }

        $get = function($name) use ($props) {
            return $props[$name][0] ?? null;
        };

        $startInfo = parseIcsDate($get('DTSTART'), $timezone);
        if (!$startInfo) continue;
        $start = $startInfo['date'];
        $allDay = $startInfo['allDay'];

        $endInfo = parseIcsDate($get('DTEND'), $timezone);
        if ($endInfo) {
            $duration = $endInfo['date']->getTimestamp() - $start->getTimestamp();
        } else {
            $duration = $allDay ? 86400 : 0;
        }

        $rruleProp = $get('RRULE');
        $rule = $rruleProp ? parseRule($rruleProp['value']) : [];
        $occurrences = $rruleProp
            ? recurrenceDates($start, $rule, $rangeStart, $rangeEnd)
            : (($start < $rangeEnd && $start->modify('+' . max(0, $duration) . ' seconds') >= $rangeStart) ? [$start] : []);

        $excluded = [];
        foreach (($props['EXDATE'] ?? []) as $exProp) {
            foreach (explode(',', $exProp['value']) as $exValue) {
                $copy = $exProp;
                $copy['value'] = $exValue;
                $ex = parseIcsDate($copy, $timezone);
                if ($ex) $excluded[] = $ex['date']->format('Ymd\THis');
            }
        }

        foreach ($occurrences as $occurrence) {
            if (!$occurrence) continue;
            if (in_array($occurrence->format('Ymd\THis'), $excluded, true)) continue;

            $occurrenceEnd = $occurrence->modify('+' . max(0, $duration) . ' seconds');
            if ($occurrenceEnd < $rangeStart || $occurrence >= $rangeEnd) continue;

            $events[] = [
                'calendar' => $calendar,
                'title' => unescapeIcsText($get('SUMMARY')['value'] ?? ''),
                'start' => $occurrence->format('Y-m-d H:i:s'),
                'timestamp' => $occurrence->getTimestamp(),
                'allDay' => $allDay,
                'location' => unescapeIcsText($get('LOCATION')['value'] ?? ''),
                'description' => unescapeIcsText($get('DESCRIPTION')['value'] ?? '')
            ];
        }
    }

    return $events;
}

$events = [];

$startUtc = $rangeStart->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
$endUtc = $rangeEnd->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');

foreach ($calendars as $calendar) {
    $url = rtrim($server, '/') . '/caldav.php/admin/' . rawurlencode($calendar) . '/';

    $xml = '<?xml version="1.0" encoding="utf-8"?>' .
        '<C:calendar-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">' .
        '<D:prop><D:getetag/><C:calendar-data/></D:prop>' .
        '<C:filter><C:comp-filter name="VCALENDAR">' .
        '<C:comp-filter name="VEVENT"><C:time-range start="' . $startUtc .
        '" end="' . $endUtc . '"/></C:comp-filter>' .
        '</C:comp-filter></C:filter></C:calendar-query>';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $user . ':' . $password,
        CURLOPT_CUSTOMREQUEST => 'REPORT',
        CURLOPT_POSTFIELDS => $xml,
        CURLOPT_HTTPHEADER => [
            'Depth: 1',
            'Content-Type: application/xml; charset=utf-8'
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        outputError('Verbindung zum CalDAV-Server fehlgeschlagen', [
            'calendar' => $calendar,
            'curl_error' => $curlError
        ]);
    }

    if ($httpCode !== 207) {
        outputError('CalDAV-Server lieferte keinen HTTP-Status 207', [
            'calendar' => $calendar,
            'http_code' => $httpCode,
            'response' => substr($response, 0, 1500)
        ]);
    }

    $dom = new DOMDocument();
    if (!@$dom->loadXML($response)) {
        outputError('CalDAV-Antwort enthält kein gültiges XML', ['calendar' => $calendar]);
    }

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('C', 'urn:ietf:params:xml:ns:caldav');

    foreach ($xpath->query('//C:calendar-data') as $node) {
        $events = array_merge(
            $events,
            parseEvent($node->textContent, $calendar, $rangeStart, $rangeEnd, $timezone)
        );
    }
}

usort($events, function($a, $b) {
    return $a['timestamp'] <=> $b['timestamp'];
});

// Identische Vorkommen aus mehrfach eingebundenen Kalendern nicht doppelt ausgeben.
$unique = [];
$result = [];
foreach ($events as $event) {
    $key = $event['calendar'] . '|' . $event['title'] . '|' . $event['start'];
    if (isset($unique[$key])) continue;
    $unique[$key] = true;
    $result[] = $event;
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
