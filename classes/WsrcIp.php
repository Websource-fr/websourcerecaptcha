<?php
/**
 * Websource reCAPTCHA — outils IP : anonymisation et listes d'exclusion (IPv4/IPv6, CIDR).
 *
 * @author    Websource
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class WsrcIp
{
    /**
     * Anonymise une adresse : IPv4 tronquée au /24 (dernier octet à 0), IPv6 tronquée au /48.
     * Retourne une chaîne vide si l'adresse est invalide.
     *
     * @param string $ip
     *
     * @return string
     */
    public static function anonymize($ip)
    {
        $ip = trim((string) $ip);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            $parts[3] = '0';

            return implode('.', $parts);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $bin = @inet_pton($ip);
            if ($bin === false || strlen($bin) !== 16) {
                return '';
            }
            $ntop = @inet_ntop(substr($bin, 0, 6) . str_repeat("\0", 10));

            return $ntop === false ? '' : $ntop;
        }

        return '';
    }

    /**
     * Découpe une liste saisie en back-office (une règle par ligne, virgules et espaces tolérés).
     *
     * @param string $raw
     *
     * @return string[]
     */
    public static function parseList($raw)
    {
        $rules = preg_split('/[\s,;]+/', (string) $raw);
        $out = [];
        foreach ($rules ?: [] as $rule) {
            $rule = trim($rule);
            if ($rule !== '') {
                $out[] = $rule;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Règle valide ? Adresse IP simple ou bloc CIDR (ex. 192.168.1.0/24, 2001:db8::/32).
     *
     * @param string $rule
     *
     * @return bool
     */
    public static function isValidRule($rule)
    {
        $rule = trim((string) $rule);
        if (strpos($rule, '/') === false) {
            return (bool) filter_var($rule, FILTER_VALIDATE_IP);
        }
        $parts = explode('/', $rule, 2);
        if (!filter_var($parts[0], FILTER_VALIDATE_IP) || !ctype_digit($parts[1])) {
            return false;
        }
        $max = filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 32 : 128;

        return (int) $parts[1] >= 0 && (int) $parts[1] <= $max;
    }

    /**
     * L'adresse appartient-elle à l'une des règles ?
     *
     * @param string $ip
     * @param string[] $rules
     *
     * @return bool
     */
    public static function inList($ip, array $rules)
    {
        foreach ($rules as $rule) {
            if (self::matches($ip, $rule)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Comparaison binaire (inet_pton) : fonctionne à l'identique en IPv4 et IPv6.
     *
     * @param string $ip
     * @param string $rule
     *
     * @return bool
     */
    public static function matches($ip, $rule)
    {
        $ipBin = @inet_pton(trim((string) $ip));
        if ($ipBin === false) {
            return false;
        }
        $rule = trim((string) $rule);
        if (strpos($rule, '/') === false) {
            $ruleBin = @inet_pton($rule);

            return $ruleBin !== false && $ruleBin === $ipBin;
        }

        $parts = explode('/', $rule, 2);
        $subnet = @inet_pton($parts[0]);
        if ($subnet === false || strlen($subnet) !== strlen($ipBin) || !ctype_digit($parts[1])) {
            return false;
        }
        $bits = (int) $parts[1];
        if ($bits > strlen($ipBin) * 8) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnet, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($ipBin[$bytes]) & $mask) === (ord($subnet[$bytes]) & $mask);
    }
}
