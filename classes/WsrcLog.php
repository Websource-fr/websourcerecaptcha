<?php
/**
 * Websource reCAPTCHA — journal des échecs et compteurs.
 *
 * Deux tables :
 *  - wsrc_log  : détail des échecs (IP anonymisée, score, codes d'erreur), désactivable, purgé après 30 jours ;
 *  - wsrc_stat : compteurs quotidiens agrégés par formulaire (aucune donnée personnelle), pour les statistiques 7 / 30 jours.
 *
 * @author    Websource
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class WsrcLog
{
    /** Durée de conservation du journal détaillé (jours). */
    const RETENTION_DAYS = 30;

    /** Durée de conservation des compteurs agrégés (jours). */
    const STAT_RETENTION_DAYS = 90;

    /**
     * @return bool
     */
    public static function installSql()
    {
        $p = _DB_PREFIX_;
        $engine = _MYSQL_ENGINE_;
        $queries = [
            "CREATE TABLE IF NOT EXISTS `{$p}wsrc_log` (
                `id_wsrc_log` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT UNSIGNED NOT NULL DEFAULT 0,
                `form` VARCHAR(32) NOT NULL,
                `ip` VARCHAR(45) NOT NULL DEFAULT '',
                `score` DECIMAL(3,2) DEFAULT NULL,
                `error_codes` VARCHAR(255) NOT NULL DEFAULT '',
                `blocked` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_wsrc_log`),
                KEY `shop_date` (`id_shop`, `date_add`)
            ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$p}wsrc_stat` (
                `id_shop` INT UNSIGNED NOT NULL DEFAULT 0,
                `day` DATE NOT NULL,
                `form` VARCHAR(32) NOT NULL,
                `passed` INT UNSIGNED NOT NULL DEFAULT 0,
                `blocked` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id_shop`, `day`, `form`)
            ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4",
        ];
        foreach ($queries as $sql) {
            if (!Db::getInstance()->execute($sql)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return bool
     */
    public static function uninstallSql()
    {
        $p = _DB_PREFIX_;
        Db::getInstance()->execute("DROP TABLE IF EXISTS `{$p}wsrc_log`");
        Db::getInstance()->execute("DROP TABLE IF EXISTS `{$p}wsrc_stat`");

        return true;
    }

    /**
     * Enregistre le résultat d'une vérification.
     *
     * @param int $idShop
     * @param string $form clé du formulaire (contact, login…)
     * @param bool $blocked soumission refusée ?
     * @param bool $detail écrire une ligne dans le journal détaillé ?
     * @param string $ip adresse brute (anonymisée ici, jamais stockée en clair)
     * @param float|null $score
     * @param string[] $codes
     */
    public static function record($idShop, $form, $blocked, $detail, $ip, $score, array $codes)
    {
        $db = Db::getInstance();
        $p = _DB_PREFIX_;
        $form = pSQL(substr(preg_replace('/[^a-z0-9_]/', '', (string) $form), 0, 32));
        $idShop = (int) $idShop;
        $day = date('Y-m-d');
        $passedInc = $blocked ? 0 : 1;
        $blockedInc = $blocked ? 1 : 0;

        $db->execute(
            "INSERT INTO `{$p}wsrc_stat` (`id_shop`, `day`, `form`, `passed`, `blocked`)
            VALUES ({$idShop}, '" . pSQL($day) . "', '{$form}', {$passedInc}, {$blockedInc})
            ON DUPLICATE KEY UPDATE `passed` = `passed` + {$passedInc}, `blocked` = `blocked` + {$blockedInc}"
        );

        if ($detail) {
            $codesStr = substr(implode(',', array_map(function ($c) {
                return preg_replace('/[^a-z0-9\-_]/i', '', (string) $c);
            }, $codes)), 0, 255);
            $db->insert('wsrc_log', [
                'id_shop' => $idShop,
                'form' => $form,
                'ip' => pSQL(WsrcIp::anonymize($ip)),
                'score' => $score === null ? null : (float) round(max(0, min(1, $score)), 2),
                'error_codes' => pSQL($codesStr),
                'blocked' => (int) $blockedInc,
                'date_add' => date('Y-m-d H:i:s'),
            ], true);
        }

        self::maybePurge();
    }

    /**
     * Purge au plus une fois par jour (pas de tâche cron nécessaire).
     */
    public static function maybePurge()
    {
        $last = (int) Configuration::getGlobalValue('WSRC_LAST_PURGE');
        if ($last > time() - 86400) {
            return;
        }
        Configuration::updateGlobalValue('WSRC_LAST_PURGE', time());
        self::purge();
    }

    /**
     * Supprime les lignes du journal de plus de 30 jours et les compteurs de plus de 90 jours.
     */
    public static function purge()
    {
        $p = _DB_PREFIX_;
        Db::getInstance()->execute(
            "DELETE FROM `{$p}wsrc_log` WHERE `date_add` < '" . pSQL(date('Y-m-d H:i:s', time() - self::RETENTION_DAYS * 86400)) . "'"
        );
        Db::getInstance()->execute(
            "DELETE FROM `{$p}wsrc_stat` WHERE `day` < '" . pSQL(date('Y-m-d', time() - self::STAT_RETENTION_DAYS * 86400)) . "'"
        );
    }

    /**
     * Vide le journal détaillé d'une boutique.
     *
     * @param int $idShop
     */
    public static function clear($idShop)
    {
        Db::getInstance()->delete('wsrc_log', '`id_shop` = ' . (int) $idShop);
    }

    /**
     * Totaux sur N jours (aujourd'hui inclus) : ['passed' => int, 'blocked' => int].
     *
     * @param int $idShop
     * @param int $days
     *
     * @return array
     */
    public static function totals($idShop, $days)
    {
        $p = _DB_PREFIX_;
        $row = Db::getInstance()->getRow(
            "SELECT COALESCE(SUM(`passed`), 0) AS passed, COALESCE(SUM(`blocked`), 0) AS blocked
            FROM `{$p}wsrc_stat`
            WHERE `id_shop` = " . (int) $idShop . " AND `day` >= '" . pSQL(self::since($days)) . "'"
        );

        return [
            'passed' => $row ? (int) $row['passed'] : 0,
            'blocked' => $row ? (int) $row['blocked'] : 0,
        ];
    }

    /**
     * Totaux par formulaire sur N jours : [form => ['passed' => int, 'blocked' => int]].
     *
     * @param int $idShop
     * @param int $days
     *
     * @return array
     */
    public static function totalsByForm($idShop, $days)
    {
        $p = _DB_PREFIX_;
        $rows = Db::getInstance()->executeS(
            "SELECT `form`, SUM(`passed`) AS passed, SUM(`blocked`) AS blocked
            FROM `{$p}wsrc_stat`
            WHERE `id_shop` = " . (int) $idShop . " AND `day` >= '" . pSQL(self::since($days)) . "'
            GROUP BY `form`"
        );
        $out = [];
        foreach ($rows ?: [] as $row) {
            $out[$row['form']] = ['passed' => (int) $row['passed'], 'blocked' => (int) $row['blocked']];
        }

        return $out;
    }

    /**
     * Dernières entrées du journal.
     *
     * @param int $idShop
     * @param int $limit
     *
     * @return array
     */
    public static function latest($idShop, $limit = 50)
    {
        $p = _DB_PREFIX_;
        $rows = Db::getInstance()->executeS(
            "SELECT `date_add`, `form`, `ip`, `score`, `error_codes`, `blocked`
            FROM `{$p}wsrc_log`
            WHERE `id_shop` = " . (int) $idShop . '
            ORDER BY `id_wsrc_log` DESC
            LIMIT ' . max(1, (int) $limit)
        );

        return $rows ?: [];
    }

    /**
     * Date de début (Y-m-d) d'une fenêtre de N jours incluant aujourd'hui.
     *
     * @param int $days
     *
     * @return string
     */
    private static function since($days)
    {
        return date('Y-m-d', strtotime('-' . max(0, (int) $days - 1) . ' days'));
    }
}
