<?php
/**
 * Renders an embedded Google Calendar iframe for hall reservations.
 * Uses the same primary calendar where approved reservations are created.
 *
 * Optional: define HALL_CALENDAR_EMBED_ID in a config to use a specific calendar
 * (e.g. a dedicated public hall calendar) instead of fetching from API.
 */
if (!defined('HALL_CALENDAR_EMBED_ID')) {
    define('HALL_CALENDAR_EMBED_ID', '');
}

$calendarEmbedId = HALL_CALENDAR_EMBED_ID;

if ($calendarEmbedId === '' && function_exists('getGoogleClientWithToken')) {
    $client = getGoogleClientWithToken();
    if ($client instanceof Google_Client) {
        try {
            $calendarService = new Google_Service_Calendar($client);
            $list = $calendarService->calendarList->listCalendarList();
            foreach ($list->getItems() as $cal) {
                if ($cal->getPrimary()) {
                    $calendarEmbedId = $cal->getId();
                    break;
                }
            }
        } catch (Throwable $e) {
            $calendarEmbedId = '';
        }
    }
}

$calendarEmbedHeight = isset($calendarEmbedHeight) ? (int) $calendarEmbedHeight : 400;
$calendarEmbedTitle = isset($calendarEmbedTitle) ? $calendarEmbedTitle : 'Hall reservation calendar';
?>
<?php if (!empty($calendarEmbedId)): ?>
<div class="calendar-embed-wrapper">
    <h3 class="calendar-embed-title"><?php echo htmlspecialchars($calendarEmbedTitle); ?></h3>
    <p class="calendar-embed-note">Blocked times show approved hall reservations.</p>
    <iframe src="https://calendar.google.com/calendar/embed?src=<?php echo urlencode($calendarEmbedId); ?>&ctz=Asia%2FColombo&mode=WEEK&showTitle=0&showNav=1&showDate=1&showPrint=0&showTabs=1&showCalendars=0&showTz=0"
            style="border:0; width:100%; height:<?php echo $calendarEmbedHeight; ?>px;"
            frameborder="0"
            scrolling="no"
            title="<?php echo htmlspecialchars($calendarEmbedTitle); ?>"></iframe>
</div>
<?php endif; ?>
