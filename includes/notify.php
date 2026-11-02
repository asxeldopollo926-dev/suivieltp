<?php
/**
 * Notifications aux parents (et e-mails système).
 *
 * Tous les messages passent par la table `notifications` :
 *   - SMTP configuré  -> statut « en_attente » puis « envoye » / « echec » ;
 *   - SMTP non activé -> statut « journal » : le message est conservé et consultable (page Notifications).
 * Les SMS ne sont pas encore branchés à un fournisseur : ils sont toujours journalisés (voir sms_send()).
 *
 * Utilisation dans une page :
 *     notify_parent($eleve, 'absence', 'Absence de votre enfant', "Texte…");   // envoi immédiat
 *     notify_parent($eleve, 'bulletin', '…', '…', false);                       // mis en file (envoi groupé)
 */
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/etablissement.php';

/** Événements pouvant déclencher un message aux parents (activables dans la page Notifications). */
const NOTIF_EVENTS = [
    'absence'  => 'Absence ou retard enregistré',
    'bulletin' => 'Bulletin publié',
    'paiement' => 'Paiement enregistré',
];

function notif_event_enabled(string $event): bool
{
    return setting('notif_' . $event, '1') === '1';
}

/** Pied de message commun. */
function notif_signature(?array $etab = null): string
{
    $etab = $etab ?? current_etab();
    return "\n\n—\n" . ($etab['nom'] ?? 'SuiviLTP')
         . "\nEspace parents : " . app_url('login_parent.php')
         . "\nCe message est envoyé automatiquement, merci de ne pas y répondre.";
}

/** Ajoute un message à la file. Retourne son id. */
function notif_queue(string $canal, string $to, string $subject, string $body, array $meta = []): int
{
    global $pdo;
    $statut = 'journal';
    if ($canal === 'email' && smtp_config()['enabled']) {
        $statut = 'en_attente';
    }
    $pdo->prepare('INSERT INTO notifications (etablissement_id, eleve_id, evenement, canal, destinataire, sujet, corps, statut, erreur)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([
            $meta['etablissement_id'] ?? (etab_id() ?: null),
            $meta['eleve_id'] ?? null,
            mb_substr($meta['evenement'] ?? '', 0, 30),
            $canal,
            mb_substr($to, 0, 150),
            mb_substr($subject, 0, 255),
            $body,
            $statut,
            $statut === 'journal' ? ($canal === 'sms' ? 'Fournisseur SMS non configuré' : 'Envoi e-mail désactivé') : '',
        ]);
    return (int)$pdo->lastInsertId();
}

/** Tente d'envoyer un message en attente. Retourne true si envoyé. */
function notif_send(int $id): bool
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE id = ? AND statut IN ('en_attente', 'echec')");
    $stmt->execute([$id]);
    $n = $stmt->fetch();
    if (!$n || $n['canal'] !== 'email') {
        return false;
    }
    try {
        smtp_send(smtp_config(), $n['destinataire'], $n['sujet'], $n['corps']);
        $pdo->prepare("UPDATE notifications SET statut = 'envoye', erreur = '', tentatives = tentatives + 1, sent_at = NOW() WHERE id = ?")
            ->execute([$id]);
        return true;
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE notifications SET statut = 'echec', erreur = ?, tentatives = tentatives + 1 WHERE id = ?")
            ->execute([mb_substr($e->getMessage(), 0, 255), $id]);
        error_log('[SuiviLTP] Notification ' . $id . ' : ' . $e->getMessage());
        return false;
    }
}

/** Envoie jusqu'à $limit messages en attente (ou en échec avec moins de 3 tentatives). */
function notif_process(int $limit = 20): array
{
    global $pdo;
    $result = ['envoyes' => 0, 'echecs' => 0];
    if (!smtp_config()['enabled']) {
        return $result;
    }
    $ids = $pdo->query("SELECT id FROM notifications WHERE canal = 'email' AND (statut = 'en_attente' OR (statut = 'echec' AND tentatives < 3))
                        ORDER BY id LIMIT " . max(1, $limit))->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        notif_send((int)$id) ? $result['envoyes']++ : $result['echecs']++;
    }
    return $result;
}

function notif_pending_count(): int
{
    global $pdo;
    return (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE statut = 'en_attente' OR (statut = 'echec' AND tentatives < 3)")->fetchColumn();
}

/** Point d'attache des SMS : à compléter avec l'API du fournisseur choisi. */
function sms_send(string $phone, string $message): void
{
    throw new RuntimeException('Aucun fournisseur SMS configuré.');
}

/**
 * Prévient le(s) contact(s) parent d'un élève.
 * $eleve doit contenir id, etablissement_id, parent_email, parent_telephone.
 * $immediate : true = envoi tout de suite (1 message), false = laissé en file (envois groupés).
 * Retourne le nombre de messages créés.
 */
function notify_parent(array $eleve, string $event, string $subject, string $body, bool $immediate = true): int
{
    if (!notif_event_enabled($event)) {
        return 0;
    }
    $etab = etablissement((int)($eleve['etablissement_id'] ?? etab_id()));
    $meta = ['etablissement_id' => $etab['id'] ?? null, 'eleve_id' => (int)$eleve['id'], 'evenement' => $event];
    $full = "Bonjour,\n\n" . trim($body) . notif_signature($etab);
    $count = 0;

    $email = trim($eleve['parent_email'] ?? '');
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $id = notif_queue('email', $email, $subject . ' — ' . ($etab['nom'] ?? 'SuiviLTP'), $full, $meta);
        if ($immediate) {
            notif_send($id);
        }
        $count++;
    }
    $phone = trim($eleve['parent_telephone'] ?? '');
    if ($phone !== '' && setting('sms_enabled', '0') === '1') {
        notif_queue('sms', $phone, $subject, mb_substr(trim($body), 0, 300), $meta);
        $count++;
    }
    return $count;
}
