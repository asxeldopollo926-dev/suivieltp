<?php
require_once 'auth_functions.php';
require_once 'includes/layout.php';
require_once 'includes/notify.php';
require_page_access();

const STATUTS = [
    'en_attente' => ['En attente', 'badge-info'],
    'envoye'     => ['Envoyé', 'badge-success'],
    'echec'      => ['Échec', 'badge-danger'],
    'journal'    => ['Journal seulement', 'badge-warning'],
];

/** Les liens de réinitialisation de mot de passe ne doivent pas être lisibles dans le journal. */
function mask_secrets(string $text): string
{
    return preg_replace('/token=[A-Za-z0-9]+/', 'token=••••••••', $text);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_smtp') {
            $host = trim($_POST['smtp_host'] ?? '');
            $port = (int)($_POST['smtp_port'] ?? 0);
            $security = in_array($_POST['smtp_security'] ?? '', ['tls', 'ssl', 'none'], true) ? $_POST['smtp_security'] : 'tls';
            $from = trim($_POST['mail_from'] ?? '');
            $enabled = !empty($_POST['smtp_enabled']);
            $errors = [];
            if ($enabled && ($host === '' || !preg_match('/^[A-Za-z0-9.-]+$/', $host))) { $errors[] = 'Serveur SMTP invalide.'; }
            if ($port < 1 || $port > 65535) { $errors[] = 'Port invalide.'; }
            if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) { $errors[] = "Adresse d'expédition invalide."; }
            if ($enabled && $from === '') { $errors[] = "L'adresse d'expédition est obligatoire."; }
            if ($errors) {
                flash('error', implode(' ', $errors));
            } else {
                setting_set('smtp_enabled', $enabled ? '1' : '0');
                setting_set('smtp_host', $host);
                setting_set('smtp_port', (string)$port);
                setting_set('smtp_security', $security);
                setting_set('smtp_user', trim($_POST['smtp_user'] ?? ''));
                if (($_POST['smtp_pass'] ?? '') !== '') {
                    setting_set('smtp_pass', $_POST['smtp_pass']);
                }
                setting_set('mail_from', $from);
                setting_set('mail_from_name', mb_substr(trim($_POST['mail_from_name'] ?? 'SuiviLTP'), 0, 100) ?: 'SuiviLTP');
                flash('success', $enabled ? 'Réglages enregistrés : envoi des e-mails activé. Faites un envoi de test.' : 'Réglages enregistrés (envoi désactivé : les messages sont seulement journalisés).');
            }
        } elseif ($action === 'save_events') {
            foreach (array_keys(NOTIF_EVENTS) as $ev) {
                setting_set('notif_' . $ev, !empty($_POST['ev'][$ev]) ? '1' : '0');
            }
            flash('success', 'Choix des notifications enregistré.');
        } elseif ($action === 'test') {
            $to = trim($_POST['test_to'] ?? '');
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                flash('error', 'Adresse de test invalide.');
            } elseif (!smtp_config()['enabled']) {
                flash('error', "Activez d'abord l'envoi des e-mails et enregistrez les réglages.");
            } else {
                try {
                    smtp_send(smtp_config(), $to, 'Test SuiviLTP', "Bonjour,\n\nCeci est un message de test envoyé depuis SuiviLTP le " . date('d/m/Y à H:i') . ".\nLa configuration de l'envoi d'e-mails fonctionne.");
                    flash('success', "E-mail de test envoyé à $to. Vérifiez la boîte de réception (et les spams).");
                } catch (RuntimeException $e) {
                    flash('error', 'Échec de l\'envoi : ' . $e->getMessage());
                }
            }
        } elseif ($action === 'process') {
            $r = notif_process(25);
            $left = notif_pending_count();
            flash($r['echecs'] ? 'warning' : 'success', "{$r['envoyes']} message(s) envoyé(s), {$r['echecs']} échec(s). Reste en file : $left.");
        } elseif ($action === 'retry') {
            $id = (int)($_POST['id'] ?? 0);
            // Un message seulement journalisé (envoi désactivé à l'époque) peut être envoyé maintenant.
            $pdo->prepare("UPDATE notifications SET statut = 'en_attente' WHERE id = ? AND statut = 'journal' AND canal = 'email'")->execute([$id]);
            flash(notif_send($id) ? 'success' : 'error', notif_send_last_status($id));
        } elseif ($action === 'purge') {
            $n = $pdo->exec("DELETE FROM notifications WHERE created_at < (NOW() - INTERVAL 90 DAY) AND statut IN ('envoye', 'journal')");
            flash('success', "$n ancien(s) message(s) supprimé(s) du journal.");
        }
    } catch (Throwable $e) {
        flash('error', log_error($e));
    }
    redirect('notifications.php');
}

function notif_send_last_status(int $id): string
{
    global $pdo;
    $stmt = $pdo->prepare('SELECT statut, erreur FROM notifications WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        return 'Message introuvable.';
    }
    return $row['statut'] === 'envoye' ? 'Message envoyé.' : 'Échec : ' . ($row['erreur'] ?: 'envoi impossible (e-mail désactivé ?)');
}

// Journal
$statut = isset(STATUTS[$_GET['statut'] ?? '']) ? $_GET['statut'] : '';
$where = ' WHERE (n.etablissement_id = ? OR n.etablissement_id IS NULL)';
$params = [etab_id()];
if ($statut !== '') {
    $where .= ' AND n.statut = ?';
    $params[] = $statut;
}
$limit = 25;
$stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications n' . $where);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $limit));
$page = min(max(1, (int)($_GET['page'] ?? 1)), $totalPages);
$stmt = $pdo->prepare('SELECT n.*, e.nom, e.prenoms FROM notifications n LEFT JOIN eleves e ON e.id = n.eleve_id'
                      . $where . ' ORDER BY n.id DESC LIMIT ' . $limit . ' OFFSET ' . (($page - 1) * $limit));
$stmt->execute($params);
$rows = $stmt->fetchAll();

$counts = array_fill_keys(array_keys(STATUTS), 0);
$stmt = $pdo->prepare('SELECT statut, COUNT(*) n FROM notifications WHERE etablissement_id = ? OR etablissement_id IS NULL GROUP BY statut');
$stmt->execute([etab_id()]);
foreach ($stmt as $r) {
    $counts[$r['statut']] = (int)$r['n'];
}
$stmt = $pdo->prepare("SELECT COUNT(*) FROM eleves WHERE etablissement_id = ? AND parent_email <> ''");
$stmt->execute([etab_id()]);
$withEmail = (int)$stmt->fetchColumn();
$stmt = $pdo->prepare('SELECT COUNT(*) FROM eleves WHERE etablissement_id = ?');
$stmt->execute([etab_id()]);
$totalEleves = (int)$stmt->fetchColumn();

$cfg = smtp_config();
$pending = notif_pending_count();

layout_start([
    'title'    => 'Notifications',
    'subtitle' => 'E-mails automatiques aux parents (absences, bulletins, paiements) et réinitialisations de mot de passe.',
    'actions'  => $pending && $cfg['enabled']
        ? '<form method="POST" class="inline">' . csrf_field() . '<input type="hidden" name="action" value="process"><button class="btn btn-primary">' . icon('send', 18) . ' Envoyer la file (' . $pending . ')</button></form>'
        : '',
]);
?>
<?php if (!$cfg['enabled']): ?>
<div class="alert alert-warning">
    <?= icon('alert', 18) ?>
    <div><strong>L'envoi d'e-mails n'est pas encore activé.</strong> Les messages sont créés mais seulement conservés dans le journal ci-dessous.
    Renseignez un compte e-mail (Gmail ou celui de l'établissement) puis cochez « Activer l'envoi ».</div>
</div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon success"><?= icon('check', 22) ?></div><div><div class="stat-label">Envoyés</div><div class="stat-value"><?= $counts['envoye'] ?></div></div></div>
    <div class="stat-card"><div class="stat-icon"><?= icon('clock', 22) ?></div><div><div class="stat-label">En attente</div><div class="stat-value"><?= $counts['en_attente'] ?></div></div></div>
    <div class="stat-card"><div class="stat-icon danger"><?= icon('alert', 22) ?></div><div><div class="stat-label">Échecs</div><div class="stat-value"><?= $counts['echec'] ?></div></div></div>
    <div class="stat-card"><div class="stat-icon warning"><?= icon('mail', 22) ?></div><div><div class="stat-label">Parents joignables</div><div class="stat-value"><?= $withEmail ?> / <?= $totalEleves ?></div></div></div>
</div>

<div class="grid grid-2">
    <section class="card">
        <h2 class="card-title mb-2"><?= icon('mail') ?> Compte d'envoi (SMTP)</h2>
        <form method="POST" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_smtp">
            <div class="form-grid">
                <div class="form-group"><label for="smtp_host">Serveur SMTP</label><input id="smtp_host" name="smtp_host" value="<?= e($cfg['host']) ?>" placeholder="smtp.gmail.com"></div>
                <div class="form-group"><label for="smtp_port">Port</label><input id="smtp_port" name="smtp_port" type="number" value="<?= (int)$cfg['port'] ?>" min="1" max="65535"></div>
                <div class="form-group">
                    <label for="smtp_security">Sécurité</label>
                    <select id="smtp_security" name="smtp_security">
                        <?php foreach (['tls' => 'STARTTLS (port 587)', 'ssl' => 'SSL (port 465)', 'none' => 'Aucune (déconseillé)'] as $k => $v): ?>
                            <option value="<?= $k ?>" <?= $cfg['security'] === $k ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label for="smtp_user">Identifiant</label><input id="smtp_user" name="smtp_user" value="<?= e($cfg['user']) ?>" placeholder="ecole@gmail.com" autocomplete="off"></div>
                <div class="form-group">
                    <label for="smtp_pass">Mot de passe</label>
                    <input id="smtp_pass" name="smtp_pass" type="password" placeholder="<?= $cfg['pass'] !== '' ? '•••••••• (inchangé)' : '' ?>" autocomplete="new-password">
                </div>
                <div class="form-group"><label for="mail_from">Adresse d'expédition</label><input id="mail_from" name="mail_from" type="email" value="<?= e($cfg['from']) ?>"></div>
                <div class="form-group"><label for="mail_from_name">Nom affiché</label><input id="mail_from_name" name="mail_from_name" value="<?= e($cfg['from_name']) ?>"></div>
            </div>
            <label class="mt-2" style="display:flex;gap:8px;align-items:center;font-weight:600">
                <input type="checkbox" name="smtp_enabled" value="1" <?= $cfg['enabled'] ? 'checked' : '' ?>> Activer l'envoi des e-mails
            </label>
            <p class="form-help">Gmail : activez la validation en deux étapes puis créez un « mot de passe d'application » (Compte Google → Sécurité) et utilisez-le ici.</p>
            <div class="form-actions"><button type="submit" class="btn btn-primary">Enregistrer</button></div>
        </form>
        <form method="POST" class="filters mt-2">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="test">
            <input type="email" name="test_to" placeholder="Adresse pour un e-mail de test" required class="grow" aria-label="Adresse de test">
            <button type="submit" class="btn btn-outline"><?= icon('send', 16) ?> Tester</button>
        </form>
    </section>

    <section class="card">
        <h2 class="card-title mb-2"><?= icon('info') ?> Messages envoyés aux parents</h2>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_events">
            <?php foreach (NOTIF_EVENTS as $ev => $label): ?>
                <label style="display:flex;gap:8px;align-items:center;padding:8px 0;border-bottom:1px solid var(--border)">
                    <input type="checkbox" name="ev[<?= $ev ?>]" value="1" <?= notif_event_enabled($ev) ? 'checked' : '' ?>> <?= e($label) ?>
                </label>
            <?php endforeach; ?>
            <div class="form-actions"><button type="submit" class="btn btn-primary">Enregistrer</button></div>
        </form>
        <p class="form-help">Seuls les élèves dont la fiche contient l'e-mail d'un parent sont concernés (fiche élève → Modifier).</p>
        <div class="alert alert-info mt-2 mb-0">
            <?= icon('info', 18) ?>
            <div><strong>SMS :</strong> l'emplacement est prêt (fonction <span class="code">sms_send()</span> dans <span class="code">includes/notify.php</span>).
            Il suffira d'y brancher l'API du fournisseur choisi (Twilio, fournisseur local…) avec ses identifiants.</div>
        </div>
    </section>
</div>

<section class="card section">
    <div class="card-header">
        <div>
            <h2 class="card-title"><?= icon('clock') ?> Journal</h2>
            <p class="card-subtitle"><?= $total ?> message(s) pour <?= e(current_etab()['nom'] ?? '') ?></p>
        </div>
        <div class="btn-group">
            <form method="GET" class="inline">
                <select name="statut" data-autosubmit class="input" aria-label="Filtrer par statut">
                    <option value="">Tous les statuts</option>
                    <?php foreach (STATUTS as $k => [$label]): ?><option value="<?= $k ?>" <?= $statut === $k ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
                </select>
            </form>
            <form method="POST" class="inline" data-confirm="Supprimer du journal les messages envoyés ou journalisés de plus de 90 jours ?">
                <?= csrf_field() ?><input type="hidden" name="action" value="purge">
                <button class="btn btn-secondary btn-sm">Nettoyer (+90 j)</button>
            </form>
        </div>
    </div>
    <?php if (!$rows): ?>
        <div class="empty-state"><?= icon('mail', 40) ?><p>Aucun message pour le moment.</p></div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Date</th><th>Événement</th><th>Destinataire</th><th>Sujet</th><th>Statut</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $n): [$label, $badge] = STATUTS[$n['statut']]; ?>
                <tr>
                    <td><?= e(date('d/m/Y H:i', strtotime($n['created_at']))) ?></td>
                    <td><?= e(NOTIF_EVENTS[$n['evenement']] ?? ($n['evenement'] === 'reset_mdp' ? 'Mot de passe oublié' : $n['evenement'])) ?>
                        <?php if ($n['nom']): ?><div class="form-help"><?= e(mb_strtoupper($n['nom'], 'UTF-8') . ' ' . $n['prenoms']) ?></div><?php endif; ?></td>
                    <td><?= e($n['destinataire']) ?> <span class="badge"><?= e(strtoupper($n['canal'])) ?></span></td>
                    <td>
                        <details>
                            <summary><?= e($n['sujet']) ?></summary>
                            <pre style="white-space:pre-wrap;font-family:inherit;margin:8px 0 0;color:var(--muted)"><?= e(mask_secrets($n['corps'])) ?></pre>
                        </details>
                    </td>
                    <td><span class="badge <?= $badge ?>"><?= $label ?></span><?php if ($n['erreur']): ?><div class="form-help"><?= e($n['erreur']) ?></div><?php endif; ?></td>
                    <td>
                        <?php if (in_array($n['statut'], ['echec', 'en_attente', 'journal'], true) && $n['canal'] === 'email' && $cfg['enabled']): ?>
                        <form method="POST" class="inline">
                            <?= csrf_field() ?><input type="hidden" name="action" value="retry"><input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                            <button class="btn btn-sm btn-outline"><?= icon('refresh', 14) ?> Renvoyer</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= pagination($page, $totalPages) ?>
    <?php endif; ?>
</section>
<?php layout_end(); ?>
