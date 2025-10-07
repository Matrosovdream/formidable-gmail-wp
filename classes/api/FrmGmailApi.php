<?php
if ( ! defined('ABSPATH') ) { exit; }

/**
 * Low-level Google API wrapper
 * - Holds credentials + token
 * - Creates configured Google\Client and Gmail service
 * - Handles robust JSON decode and token refresh
 */
final class FrmGmailApi {
    /** @var string */
    private string $credentialsJson = '';
    /** @var array|null */
    private ?array $token = null;

    public function __construct(?string $credentialsJson = null, ?array $token = null) {
        if ($credentialsJson !== null) { $this->setCredentials($credentialsJson); }
        if ($token !== null)          { $this->setToken($token); }
    }

    public function setCredentials(string $json): void {
        $this->credentialsJson = $json;
    }

    public function setToken(?array $token): void {
        $this->token = is_array($token) ? $token : null;
    }

    /** Create a configured Google\Client using current credentials JSON */
    public function makeClient(): ?\Google\Client {
        self::ensureGoogleLib();

        $arr = self::decodeJsonRobust($this->credentialsJson);
        if ( ! is_array($arr) ) { return null; }

        $client = new \Google\Client();

        if (isset($arr['web']) || isset($arr['installed'])) {
            $conf = $arr['web'] ?? $arr['installed'];
            if (!empty($conf['client_id']))     { $client->setClientId($conf['client_id']); }
            if (!empty($conf['client_secret'])) { $client->setClientSecret($conf['client_secret']); }
        } else {
            if (!empty($arr['client_id']))     { $client->setClientId($arr['client_id']); }
            if (!empty($arr['client_secret'])) { $client->setClientSecret($arr['client_secret']); }
        }

        if ( ! $client->getClientId() || ! $client->getClientSecret() ) {
            return null;
        }

        if ($this->token) {
            $client->setAccessToken($this->token);
        }

        return $client;
    }

    /** Return a Gmail service built on a prepared client */
    public function makeGmailService(?\Google\Client $client = null): ?\Google\Service\Gmail {
        self::ensureGoogleLib();
        if (!$client) {
            $client = $this->makeClient();
        }
        if (!$client) { return null; }
        return new \Google\Service\Gmail($client);
    }

    /**
     * Refresh token if needed; returns [\Google\Client $client, ?array $newToken]
     * Caller is responsible for persisting $newToken (if not null).
     */
    public function ensureFreshToken(\Google\Client $client): array {
        $newTok = null;
        if ( $client->isAccessTokenExpired() ) {
            $refresh = $client->getRefreshToken();
            if ($refresh) {
                $client->fetchAccessTokenWithRefreshToken($refresh);
                $newTok = $client->getAccessToken();

                // Preserve prior refresh_token if Google didn’t resend it
                if ( empty($newTok['refresh_token']) && is_array($this->token) && !empty($this->token['refresh_token']) ) {
                    $newTok['refresh_token'] = $this->token['refresh_token'];
                }
                // Update our local copy too
                $this->setToken($newTok);
            }
        }
        return [$client, $newTok];
    }

    /** Robust JSON decode with unslash + BOM strip fallbacks */
    public static function decodeJsonRobust(string $json) {
        $txt = trim((string)$json);
        if ($txt === '') { return null; }

        $candidates = [$txt];
        if ( function_exists('wp_unslash') ) {
            $candidates[] = wp_unslash($txt);
        }
        $candidates[] = stripslashes($txt);

        foreach ($candidates as $cand) {
            if (substr($cand, 0, 3) === "\xEF\xBB\xBF") {
                $cand = substr($cand, 3);
            }
            $arr = json_decode($cand, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($arr)) {
                return $arr;
            }
        }
        return null;
    }

    /** Ensure Google client libraries are loaded via filesystem path */
    public static function ensureGoogleLib(): void {
        if (class_exists('\Google\Client')) { return; }

        require_once FRM_GML_BASE_URL.'/vendor/autoload.php';

    }
}
