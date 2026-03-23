   <?php
   session_start();
   require 'google-client.php';

   if (!isset($_GET['code'])) {
       echo 'Missing code';
       exit;
   }

   $client = makeGoogleClient();
   $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);

   if (isset($token['error'])) {
       echo 'OAuth error: ' . htmlspecialchars($token['error_description'] ?? $token['error']);
       exit;
   }

   // TODO: store $token (access_token + refresh_token) in your DB (e.g. in a settings table)
   file_put_contents(__DIR__ . '/google_token.json', json_encode($token));

   echo 'Google OAuth connected successfully. You can close this window.';