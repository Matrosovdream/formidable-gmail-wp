<?php
/*
Plugin Name: Formidable forms Extension - Gmail parser
Description: 
Version: 1.0
Plugin URI: 
Author URI: 
Author: Stanislav Matrosov
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Variables
define('FRM_GML_BASE_URL', __DIR__);
define('FRM_GML_BASE_PATH', plugin_dir_url(__FILE__));

// Initialize core
//require_once 'classes/FrmGmailInit.php';


add_action('init', 'formidable_gmail_init');
function formidable_gmail_init() {
    
    if( isset( $_GET['gmail'] ) ) {

        gmailTest();
        exit();

    }

}



require __DIR__ . '/vendor/autoload.php';

use Google\Client;
use Google\Service\Gmail;

function gmailTest() {

    $client = getClient();
    $service = new Gmail($client);
    
    // 1) List messages (modify q= for filters like "is:unread")
    $list = $service->users_messages->listUsersMessages('me', ['maxResults' => 10, 'q' => '']);
    $messages = $list->getMessages() ?: [];
    
    foreach ($messages as $m) {
        $msg = $service->users_messages->get('me', $m->getId(), ['format' => 'metadata', 'metadataHeaders' => ['From','Subject']]);
    
        $headers = [];
        foreach ($msg->getPayload()->getHeaders() as $h) {
            $headers[$h->getName()] = $h->getValue();
        }
    
        $from    = $headers['From']    ?? '(no From)';
        $subject = $headers['Subject'] ?? '(no Subject)';
        $snippet = $msg->getSnippet();
    
        echo "ID: {$m->getId()}\nFrom: $from\nSubject: $subject\nSnippet: $snippet\n----\n";
    }

}





function getClient(): Client {
    $client = new Client();
    $client->setApplicationName('Gmail PHP Quickstart');
    $client->setScopes([Gmail::GMAIL_READONLY]); // read-only
    $client->setAuthConfig(__DIR__ . '/credentials.json'); // from Google Cloud
    $client->setAccessType('offline');
    $client->setPrompt('select_account consent');

    $tokenPath = __DIR__ . '/token.json';
    if (file_exists($tokenPath)) {
        $accessToken = json_decode(file_get_contents($tokenPath), true);
        $client->setAccessToken($accessToken);
    }

    if ($client->isAccessTokenExpired()) {
        if ($client->getRefreshToken()) {
            $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
        } else {
            $authUrl = $client->createAuthUrl();
            echo "Open this link in your browser:\n$authUrl\n\nEnter verification code: ";
            $authCode = trim(fgets(STDIN));
            $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);
            $client->setAccessToken($accessToken);
        }
        if (!file_exists(dirname($tokenPath))) {
            mkdir(dirname($tokenPath), 0700, true);
        }
        file_put_contents($tokenPath, json_encode($client->getAccessToken()));
    }

    return $client;
}


