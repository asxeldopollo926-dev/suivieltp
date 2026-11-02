<?php
/**
 * Envoie les e-mails en attente (à planifier, par ex. toutes les 5 minutes, avec le
 * Planificateur de tâches Windows) :
 *     C:\xampp\php\php.exe C:\xampp\htdocs\ltp1\install\send_notifications.php
 * Sans planification, les messages partent quand même : à la saisie (absences, paiements)
 * ou via le bouton « Envoyer la file » de la page Notifications.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Accès interdit.');
}
$_SERVER['REQUEST_METHOD'] = 'CLI';
require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/notify.php';

if (!smtp_config()['enabled']) {
    echo "Envoi d'e-mails désactivé (page Notifications).\n";
    exit(0);
}
$total = ['envoyes' => 0, 'echecs' => 0];
do {
    $r = notif_process(25);
    $total['envoyes'] += $r['envoyes'];
    $total['echecs'] += $r['echecs'];
} while ($r['envoyes'] > 0 && $total['envoyes'] < 500);
echo date('Y-m-d H:i:s') . " : {$total['envoyes']} envoyé(s), {$total['echecs']} échec(s), " . notif_pending_count() . " en attente.\n";
