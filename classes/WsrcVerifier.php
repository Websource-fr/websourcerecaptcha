<?php
/**
 * Websource reCAPTCHA — vérification serveur du jeton auprès de Google (siteverify).
 *
 * Le jeton produit par le navigateur ne prouve rien tant qu'il n'a pas été validé côté serveur :
 * c'est cette classe qui fait foi. Elle n'écrit jamais la clé secrète dans un journal ou une erreur.
 *
 * @author    Websource
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class WsrcVerifier
{
    const ENDPOINT = 'https://www.google.com/recaptcha/api/siteverify';

    /** Délai maximal (secondes) avant de considérer Google comme injoignable. */
    const TIMEOUT = 5;

    /** Longueur maximale acceptée pour un jeton (les jetons réels font quelques centaines à ~2 000 caractères). */
    const MAX_TOKEN_LENGTH = 10000;

    /**
     * Appel HTTP brut. Retourne le JSON décodé, ou null si Google est injoignable / réponse illisible.
     * cURL si disponible, sinon flux PHP (allow_url_fopen) avec le même délai.
     *
     * @param string $secret
     * @param string $token
     * @param string $remoteIp
     *
     * @return array|null
     */
    public static function call($secret, $token, $remoteIp = '')
    {
        $fields = ['secret' => (string) $secret, 'response' => (string) $token];
        if ($remoteIp !== '' && filter_var($remoteIp, FILTER_VALIDATE_IP)) {
            $fields['remoteip'] = $remoteIp;
        }
        $body = http_build_query($fields, '', '&');
        $raw = false;

        if (function_exists('curl_init')) {
            $ch = curl_init(self::ENDPOINT);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
                CURLOPT_TIMEOUT => self::TIMEOUT,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            ]);
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($status !== 200) {
                $raw = false;
            }
            // curl_close() est sans effet depuis PHP 8.0 et déprécié en 8.5.
            if (PHP_VERSION_ID < 80000) {
                curl_close($ch);
            }
        } elseif (ini_get('allow_url_fopen')) {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body) . "\r\n",
                    'content' => $body,
                    'timeout' => self::TIMEOUT,
                    'ignore_errors' => false,
                ],
            ]);
            $raw = @file_get_contents(self::ENDPOINT, false, $context);
        }

        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $json = json_decode($raw, true);

        return is_array($json) && isset($json['success']) ? $json : null;
    }

    /**
     * Vérifie un jeton et applique les règles du module.
     *
     * Options : version (v2_checkbox|v2_invisible|v3), action (v3), threshold (v3), hostnames (liste, vide = pas de contrôle).
     * Retour : ['ok' => bool, 'unreachable' => bool, 'codes' => string[], 'score' => float|null]
     *
     * @param string $secret
     * @param string $token
     * @param string $remoteIp
     * @param array $options
     *
     * @return array
     */
    public static function verify($secret, $token, $remoteIp, array $options)
    {
        $result = ['ok' => false, 'unreachable' => false, 'codes' => [], 'score' => null];
        $token = (string) $token;

        // Contrôles locaux : inutile d'interroger Google pour un jeton absent ou manifestement forgé.
        if ($token === '') {
            $result['codes'][] = 'missing-input-response';

            return $result;
        }
        if (strlen($token) > self::MAX_TOKEN_LENGTH || !preg_match('/^[\x21-\x7E]+$/', $token)) {
            $result['codes'][] = 'invalid-input-response';

            return $result;
        }

        $json = self::call($secret, $token, $remoteIp);
        if ($json === null) {
            $result['unreachable'] = true;
            $result['codes'][] = 'google-unreachable';

            return $result;
        }

        if (isset($json['score']) && is_numeric($json['score'])) {
            $result['score'] = (float) $json['score'];
        }
        if (!empty($json['error-codes']) && is_array($json['error-codes'])) {
            foreach ($json['error-codes'] as $code) {
                $result['codes'][] = preg_replace('/[^a-z0-9\-_]/i', '', (string) $code);
            }
        }
        if (empty($json['success'])) {
            if (!$result['codes']) {
                $result['codes'][] = 'verification-failed';
            }

            return $result;
        }

        // Nom d'hôte : protège contre la réutilisation d'un jeton émis sur un autre site utilisant la même clé.
        if (!empty($options['hostnames']) && is_array($options['hostnames'])) {
            $host = isset($json['hostname']) ? strtolower((string) $json['hostname']) : '';
            if ($host === '' || !in_array($host, $options['hostnames'], true)) {
                $result['codes'][] = 'hostname-mismatch';

                return $result;
            }
        }

        $version = isset($options['version']) ? $options['version'] : 'v2_checkbox';
        if ($version === 'v3') {
            if ($result['score'] === null) {
                // Jeton v2 envoyé alors que le module est configuré en v3 (ou clé v2) : refusé.
                $result['codes'][] = 'score-missing';

                return $result;
            }
            $expected = isset($options['action']) ? (string) $options['action'] : '';
            $action = isset($json['action']) ? (string) $json['action'] : '';
            if ($expected !== '' && $action !== $expected) {
                $result['codes'][] = 'action-mismatch';

                return $result;
            }
            $threshold = isset($options['threshold']) ? (float) $options['threshold'] : 0.5;
            if ($result['score'] < $threshold) {
                $result['codes'][] = 'low-score';

                return $result;
            }
        }

        $result['ok'] = true;

        return $result;
    }

    /**
     * Test rapide depuis le back-office : appel avec un jeton vide.
     *
     * Retour : ['status' => invalid|testkey|reachable|unreachable|unknown, 'codes' => string[]]
     *  - invalid     : Google signale une clé secrète invalide (invalid-input-secret / missing-input-secret) ;
     *  - testkey     : succès avec un jeton vide = clé secrète de test publique de Google ;
     *  - reachable   : Google répond mais ne peut juger la clé sans jeton réel (invalid/missing-input-response) ;
     *  - unreachable : délai dépassé ou connexion impossible.
     *
     * @param string $secret
     *
     * @return array
     */
    public static function testSecret($secret)
    {
        $json = self::call($secret, '');
        if ($json === null) {
            return ['status' => 'unreachable', 'codes' => []];
        }
        if (!empty($json['success'])) {
            return ['status' => 'testkey', 'codes' => []];
        }
        $codes = [];
        if (!empty($json['error-codes']) && is_array($json['error-codes'])) {
            foreach ($json['error-codes'] as $code) {
                $codes[] = preg_replace('/[^a-z0-9\-_]/i', '', (string) $code);
            }
        }
        if (in_array('invalid-input-secret', $codes, true) || in_array('missing-input-secret', $codes, true)) {
            return ['status' => 'invalid', 'codes' => $codes];
        }
        if (in_array('missing-input-response', $codes, true) || in_array('invalid-input-response', $codes, true)) {
            return ['status' => 'reachable', 'codes' => $codes];
        }

        return ['status' => 'unknown', 'codes' => $codes];
    }

    /**
     * Format plausible d'une clé reCAPTCHA (40 caractères, alphabet base64 « URL »).
     *
     * @param string $key
     *
     * @return bool
     */
    public static function looksLikeKey($key)
    {
        return (bool) preg_match('/^[A-Za-z0-9_\-]{40}$/', (string) $key);
    }
}
