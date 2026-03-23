<?php
require __DIR__ . '/../vendor/autoload.php';

/**
 * Base Google client without tokens.
 */
function makeGoogleClient(): Google_Client {
    $client = new Google_Client();
    $client->setClientId('1032368896643-qtq4im4ghff29ed5bklpbsh9a5b0s9pp.apps.googleusercontent.com');
    $client->setClientSecret('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');
    // IMPORTANT: this must match your Google OAuth redirect URI
    $client->setRedirectUri('http://localhost/project/User/google-oauth-callback.php');
    $client->setAccessType('offline');
    $client->setPrompt('consent');
    $client->setScopes([
        Google_Service_Calendar::CALENDAR_EVENTS,
        'https://www.googleapis.com/auth/calendar.readonly', // for calendarList (embed ID)
        'https://www.googleapis.com/auth/forms.body',
        'https://www.googleapis.com/auth/gmail.send',
        'https://www.googleapis.com/auth/spreadsheets',
        'https://www.googleapis.com/auth/drive.file',
    ]);
    return $client;
}


/**
 * Load a Google client already authenticated using the stored token.
 * Token is stored in google_token.json (created by google-oauth-callback.php).
 */
function getGoogleClientWithToken(): ?Google_Client {
    $tokenPath = __DIR__ . '/google_token.json';
    if (!file_exists($tokenPath)) {
        return null;
    }

    $token = json_decode(file_get_contents($tokenPath), true);
    if (!is_array($token)) {
        return null;
    }

    $client = makeGoogleClient();
    $client->setAccessToken($token);

    // Refresh if needed
    if ($client->isAccessTokenExpired()) {
        if ($client->getRefreshToken()) {
            $newToken = $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            if (!isset($newToken['error'])) {
                // Merge to keep refresh_token
                $merged = array_merge($token, $newToken);
                file_put_contents($tokenPath, json_encode($merged));
                $client->setAccessToken($merged);
            }
        } else {
            return null;
        }
    }

    return $client;
}

/**
 * Send an email using the Gmail API. Falls back to mail()
 * if Google OAuth is not available or sending fails.
 */
function sendGmail(string $to, string $subject, string $body): bool {
    $client = getGoogleClientWithToken();
    if (!($client instanceof Google_Client)) {
        error_log("sendGmail: no Google token available; to={$to}; subject={$subject}");
        return false;
    }

    try {
        $gmail = new Google_Service_Gmail($client);

        $rawMessageString  = "To: <{$to}>\r\n";
        $rawMessageString .= 'Subject: =?utf-8?B?' . base64_encode($subject) . "?=\r\n";
        $rawMessageString .= "MIME-Version: 1.0\r\n";
        $rawMessageString .= "Content-Type: text/plain; charset=utf-8\r\n";
        $rawMessageString .= "\r\n" . $body;

        $encodedMessage = rtrim(strtr(base64_encode($rawMessageString), '+/', '-_'), '=');

        $message = new Google_Service_Gmail_Message();
        $message->setRaw($encodedMessage);

        $gmail->users_messages->send('me', $message);
        return true;

    } catch (Throwable $e) {
        error_log("sendGmail failed: " . $e->getMessage());
        return false;
    }
}