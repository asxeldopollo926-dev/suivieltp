<?php
/** Réglages de l'application stockés en base (table app_settings). */

function settings_all(): array
{
    global $pdo;
    static $cache = null;
    if ($cache === null || !empty($GLOBALS['__settings_dirty'])) {
        $cache = [];
        try {
            foreach ($pdo->query('SELECT cle, valeur FROM app_settings') as $row) {
                $cache[$row['cle']] = $row['valeur'];
            }
        } catch (PDOException $e) {
            // Table absente : migration non lancée.
        }
        $GLOBALS['__settings_dirty'] = false;
    }
    return $cache;
}

function setting(string $key, string $default = ''): string
{
    return settings_all()[$key] ?? $default;
}

function setting_set(string $key, string $value): void
{
    global $pdo;
    $pdo->prepare('INSERT INTO app_settings (cle, valeur) VALUES (?, ?) ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)')
        ->execute([$key, $value]);
    $GLOBALS['__settings_dirty'] = true;
}

/**
 * URL absolue d'une page du site (pour les liens dans les e-mails).
 * Déduite de la requête web ; mémorisée pour les scripts en ligne de commande.
 */
function app_url(string $page = ''): string
{
    if (!empty($_SERVER['HTTP_HOST'])) {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        $base = ($https ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $dir . '/';
        if (setting('app_url') !== $base) {
            setting_set('app_url', $base);
        }
    } else {
        $base = setting('app_url', 'http://localhost/ltp1/');
    }
    return $base . ltrim($page, '/');
}
