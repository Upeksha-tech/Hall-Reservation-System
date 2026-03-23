<?php
/**
 * Returns JSON: { "isHoliday": true|false } for the given date.
 * Uses Google Calendar Sri Lanka public holidays.
 */
header('Content-Type: application/json');

$dateStr = trim($_GET['date'] ?? '');
if ($dateStr === '') {
    echo json_encode(['isHoliday' => false]);
    exit;
}

$d = DateTime::createFromFormat('Y-m-d', $dateStr);
if (!$d) {
    echo json_encode(['isHoliday' => false]);
    exit;
}

$isHoliday = false;
require_once __DIR__ . '/../Login/db.php';
require_once __DIR__ . '/google-client.php';

$googleClient = getGoogleClientWithToken();
if ($googleClient instanceof Google_Client) {
    try {
        $calendarService = new Google_Service_Calendar($googleClient);
        $holidayCalendarId = 'en.lk#holiday@group.v.calendar.google.com';
        $dateOnly = $d->format('Y-m-d');
        $timeMin = $dateOnly . 'T00:00:00+05:30';
        $timeMax = $dateOnly . 'T23:59:59+05:30';

        $events = $calendarService->events->listEvents($holidayCalendarId, [
            'timeMin'      => $timeMin,
            'timeMax'      => $timeMax,
            'singleEvents' => true,
        ]);

        $isHoliday = count($events->getItems()) > 0;
    } catch (Throwable $e) {
        $isHoliday = false;
    }
}

echo json_encode(['isHoliday' => $isHoliday]);
