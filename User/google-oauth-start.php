   <?php
   session_start();
   require 'google-client.php';

   $client = makeGoogleClient();
   $authUrl = $client->createAuthUrl();
   header('Location: ' . $authUrl);
   exit;