<?php
require_once 'auth_functions.php';
require_once 'includes/layout.php';
$professeur = require_professeur();

$profId  = (int)$professeur['id'];
$filiere = $professeur['filiere'];
const STATUTS = ['Présent', 'Présente', 'Absent', 'Absente', 'En retard'];

function valid_date(string $d): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
}

/** Heure HH:MM (ou HH:MM:SS) -> HH:MM:00 ; chaîne vide -> null ; invalide -> false. */
function parse_time(string $t)
{
    $t = trim($t);
    if ($t === '') {
        return null;
    }
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $t, $m)) {
        return false;
    }
    return $m[1] . ':' . $m[2] . ':00';
}

// ---------------------------------------------------------------------------
// Traitement des formulaires
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $back = 'absences.php';

    try {
        if ($action === 'update_status') {
            $eleveId = (int)($_POST['eleve_id'] ?? 0);
            $date    = (string)($_POST['date_absence'] ?? '');
            $statut  = (string)($_POST['statut'] ?? '');
            $matiere = trim((string)($_POST['matiere'] ?? ''));
            $debut   = parse_time((string)($_POST['heure_debut'] ?? ''));
            $fin     = parse_time((string)($_POST['heure_fin'] ?? ''));
            $back    = 'absences.php?' . http_build_query(array_filter([
                'date'   => valid_date($date) ? $date : null,
                'classe' => $_POST['classe_filter'] ?? null,
                'search' => $_POST['search_filter'] ?? null,
            ]));

            // L'élève doit appartenir à la filière du professeur
            $stmt = $pdo->prepare('SELECT id, nom, prenoms, sexe FROM eleves WHERE id = ? AND filiere = ?');
            $stmt->execute([$eleveId, $filiere]);
            $eleve = $stmt->fetch();

            if (!$eleve) {
                flash('error', "Cet élève n'appartient pas à votre filière.");
            } elseif (!valid_date($date) || $date > date('Y-m-d')) {
                flash('error', 'Date invalide (les dates futures ne sont pas acceptées).');
            } elseif (!in_array($statut, STATUTS, true)) {
                flash('error', 'Statut invalide.');
            } else {
                // Accord du statut avec le sexe de l'élève
                $feminin = $eleve['sexe'] === 'F';
                if (in_array($statut, ['Présent', 'Présente'], true)) {
                    $statut = $feminin ? 'Présente' : 'Présent';
                } elseif (in_array($statut, ['Absent', 'Absente'], true)) {
                    $statut = $feminin ? 'Absente' : 'Absent';
                }
                $isAbsent = in_array($statut, ['Absent', 'Absente'], true);

                if ($debut === false || $fin === false) {
                    flash('error', 'Heures invalides (format attendu HH:MM).');
                } elseif ($isAbsent && ($matiere === '' || !$debut || !$fin)) {
                    flash('error', "Pour une absence, indiquez la matière et les heures de début et de fin.");
                } elseif ($isAbsent && $debut >= $fin) {
                    flash('error', "L'heure de fin doit être après l'heure de début.");
                } else {
                    if (!$isAbsent) {
                        $matiere = '';
                        $debut = $fin = null;
                    }
                    $stmt = $pdo->prepare('SELECT id FROM absences WHERE eleve_id = ? AND date_absence = ? AND professeur_id = ?');
                    $stmt->execute([$eleveId, $date, $profId]);
                    $existingId = $stmt->fetchColumn();
                    $nomEleve = $eleve['prenoms'] . ' ' . mb_strtoupper($eleve['nom'], 'UTF-8');

                    if ($statut === 'Présent' || $statut === 'Présente') {
                        if ($existingId) {
                            $pdo->prepare('DELETE FROM absences WHERE id = ?')->execute([$existingId]);
                        }
                        flash('success', "$nomEleve : marqué(e) $statut le " . date_fr($date) . '.');
                    } else {
                        $matiereDb = $matiere === '' ? null : mb_substr($matiere, 0, 100);
                        if ($existingId) {
                            $pdo->prepare('UPDATE absences SET statut = ?, matiere = ?, heure_debut = ?, heure_fin = ? WHERE id = ?')
                                ->execute([$statut, $matiereDb, $debut, $fin, $existingId]);
                        } else {
                            $pdo->prepare('INSERT INTO absences (eleve_id, professeur_id, date_absence, statut, matiere, heure_debut, heure_fin) VALUES (?, ?, ?, ?, ?, ?, ?)')
                                ->execute([$eleveId, $profId, $date, $statut, $matiereDb, $debut, $fin]);
                        }
                        flash('success', "$nomEleve : $statut enregistré(e) le " . date_fr($date) . '.');
                    }
                }
            }
        } elseif ($action === 'reset_history') {
            $stmt = $pdo->prepare('DELETE FROM absences WHERE professeur_id = ?');
            $stmt->execute([$profId]);
            flash('success', "Historique réinitialisé ({$stmt->rowCount()} enregistrement(s) supprimé(s)).");
        }
    } catch (Throwable $e) {
        flash('error', log_error($e));
    }
    redirect($back);
}

// ---------------------------------------------------------------------------
// Données affichées
// ---------------------------------------------------------------------------
$search = trim($_GET['search'] ?? '');
$classeFilter = $_GET['classe'] ?? '';
$date = $_GET['date'] ?? date('Y-m-d');
if (!valid_date($date) || $date > date('Y-m-d')) {
    $date = date('Y-m-d');
}

$stmt = $pdo->prepare("SELECT DISTINCT classe FROM eleves WHERE filiere = ? ORDER BY FIELD(classe, '2nde', '1ere', 'Tle')");
$stmt->execute([$filiere]);
$classes = $stmt->fetchAll(PDO::FETCH_COLUMN);

$sql = 'SELECT e.*, a.statut AS statut_du_jour, a.matiere, a.heure_debut, a.heure_fin
        FROM eleves e
        LEFT JOIN absences a ON e.id = a.eleve_id AND a.date_absence = ? AND a.professeur_id = ?
        WHERE e.filiere = ? AND (e.nom LIKE ? OR e.prenoms LIKE ?)';
$params = [$date, $profId, $filiere, "%$search%", "%$search%"];
if ($classeFilter !== '') {
    $sql .= ' AND e.classe = ?';
    $params[] = $classeFilter;
}
$stmt = $pdo->prepare($sql . ' ORDER BY e.nom, e.prenoms');
$stmt->execute($params);
$eleves = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT a.*, e.nom AS eleve_nom, e.prenoms AS eleve_prenoms, e.classe
    FROM absences a JOIN eleves e ON a.eleve_id = e.id
    WHERE a.professeur_id = ?
    ORDER BY a.date_absence DESC, e.nom, e.prenoms
    LIMIT 200');
$stmt->execute([$profId]);
$absences = $stmt->fetchAll();

function statut_badge(string $statut): string
{
    $class = in_array($statut, ['Absent', 'Absente'], true) ? 'badge-danger'
        : ($statut === 'En retard' ? 'badge-warning' : 'badge-success');
    return '<span class="badge ' . $class . '">' . e($statut) . '</span>';
}

layout_start([
    'title'    => 'Gestion des absences',
    'subtitle' => 'Filière ' . $filiere . ' — appel du ' . date_fr($date),
    'area'     => 'prof',
    'head'     => '<style>
        .status-form { display: grid; gap: 8px; min-width: 240px; }
        .status-form .row { display: flex; gap: 6px; align-items: center; }
        .status-form .row > * { flex: 1; }
        .status-form .absence-details { display: grid; gap: 6px; }
        .status-form .absence-details.hidden { display: none; }
        .status-form .input { padding: 7px 10px; }
    </style>',
]);
?>
<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title"><?= icon('users') ?> Liste des élèves</h2>
            <p class="card-subtitle"><?= count($eleves) ?> élève(s) — les statuts affichés sont ceux du <?= e(date_fr($date)) ?></p>
        </div>
    </div>

    <form method="GET" class="filters">
        <div class="input-group grow">
            <?= icon('search', 18) ?>
            <input type="search" name="search" placeholder="Rechercher un élève…" value="<?= e($search) ?>">
        </div>
        <select name="classe" data-autosubmit aria-label="Classe">
            <option value="">Toutes les classes</option>
            <?php foreach ($classes as $c): ?>
                <option value="<?= e($c) ?>" <?= $classeFilter === $c ? 'selected' : '' ?>><?= e($c) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="date" value="<?= e($date) ?>" max="<?= date('Y-m-d') ?>" data-autosubmit aria-label="Date de l'appel">
        <button type="submit" class="btn btn-primary"><?= icon('search', 16) ?> Rechercher</button>
    </form>

    <?php if (!$eleves): ?>
        <div class="empty-state"><?= icon('search', 40) ?><p>Aucun élève trouvé.</p></div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>ID</th><th>Élève</th><th>Classe</th><th>Statut actuel</th><th>Enregistrer un statut</th></tr></thead>
            <tbody>
            <?php foreach ($eleves as $eleve):
                $f = $eleve['sexe'] === 'F';
                $current = $eleve['statut_du_jour'] ?? ($f ? 'Présente' : 'Présent');
                $isAbsent = in_array($current, ['Absent', 'Absente'], true);
            ?>
                <tr>
                    <td><?= (int)$eleve['id'] ?></td>
                    <td><span class="fw-bold"><?= e(mb_strtoupper($eleve['nom'], 'UTF-8')) ?></span> <?= e($eleve['prenoms']) ?></td>
                    <td><?= e($eleve['classe']) ?></td>
                    <td>
                        <?= statut_badge($current) ?>
                        <?php if ($isAbsent && $eleve['matiere']): ?>
                            <div class="text-muted" style="font-size:.82rem">
                                <?= e($eleve['matiere']) ?>
                                <?php if ($eleve['heure_debut'] && $eleve['heure_fin']): ?>
                                    · <?= e(substr($eleve['heure_debut'], 0, 5)) ?>–<?= e(substr($eleve['heure_fin'], 0, 5)) ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="POST" class="status-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="eleve_id" value="<?= (int)$eleve['id'] ?>">
                            <input type="hidden" name="date_absence" value="<?= e($date) ?>">
                            <input type="hidden" name="classe_filter" value="<?= e($classeFilter) ?>">
                            <input type="hidden" name="search_filter" value="<?= e($search) ?>">
                            <div class="row">
                                <select name="statut" class="input status-select" aria-label="Statut">
                                    <option value="<?= $f ? 'Présente' : 'Présent' ?>" <?= in_array($current, ['Présent', 'Présente'], true) ? 'selected' : '' ?>><?= $f ? 'Présente' : 'Présent' ?></option>
                                    <option value="<?= $f ? 'Absente' : 'Absent' ?>" <?= $isAbsent ? 'selected' : '' ?>><?= $f ? 'Absente' : 'Absent' ?></option>
                                    <option value="En retard" <?= $current === 'En retard' ? 'selected' : '' ?>>En retard</option>
                                </select>
                                <button type="submit" class="btn btn-sm btn-primary" style="flex:0 0 auto"><?= icon('check', 16) ?> Valider</button>
                            </div>
                            <div class="absence-details<?= $isAbsent ? '' : ' hidden' ?>">
                                <input type="text" name="matiere" class="input" placeholder="Matière" maxlength="100" value="<?= e($eleve['matiere'] ?? '') ?>">
                                <div class="row">
                                    <input type="time" name="heure_debut" class="input" aria-label="Heure de début" value="<?= e(substr((string)$eleve['heure_debut'], 0, 5)) ?>">
                                    <span style="flex:0 0 auto">à</span>
                                    <input type="time" name="heure_fin" class="input" aria-label="Heure de fin" value="<?= e(substr((string)$eleve['heure_fin'], 0, 5)) ?>">
                                </div>
                            </div>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title"><?= icon('clock') ?> Historique de mes saisies</h2>
            <p class="card-subtitle">Absences et retards que vous avez enregistrés (200 derniers)</p>
        </div>
        <?php if ($absences): ?>
        <form method="POST" class="inline" data-confirm="Supprimer tout l'historique de vos absences et retards ? Cette action est irréversible." data-confirm-label="Tout supprimer">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reset_history">
            <button type="submit" class="btn btn-sm btn-danger-soft"><?= icon('trash', 16) ?> Réinitialiser l'historique</button>
        </form>
        <?php endif; ?>
    </div>
    <?php if (!$absences): ?>
        <div class="empty-state"><?= icon('calendar', 40) ?><p>Aucune absence ni retard enregistré pour le moment.</p></div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Date</th><th>Élève</th><th>Classe</th><th>Statut</th><th>Matière</th><th>Horaire</th></tr></thead>
            <tbody>
            <?php foreach ($absences as $a): ?>
                <tr>
                    <td><?= e(date_fr($a['date_absence'])) ?></td>
                    <td><span class="fw-bold"><?= e(mb_strtoupper($a['eleve_nom'], 'UTF-8')) ?></span> <?= e($a['eleve_prenoms']) ?></td>
                    <td><?= e($a['classe']) ?></td>
                    <td><?= statut_badge($a['statut']) ?></td>
                    <td><?= e($a['matiere'] ?? '') ?></td>
                    <td><?= ($a['heure_debut'] && $a['heure_fin']) ? e(substr($a['heure_debut'], 0, 5) . ' – ' . substr($a['heure_fin'], 0, 5)) : '' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php
layout_end(['scripts' => <<<'JS'
<script>
// Affiche les champs matière/heures uniquement pour une absence ; un retard est enregistré immédiatement.
document.querySelectorAll('.status-select').forEach(function (select) {
    select.addEventListener('change', function () {
        var form = select.form;
        var details = form.querySelector('.absence-details');
        var absent = select.value === 'Absent' || select.value === 'Absente';
        details.classList.toggle('hidden', !absent);
        details.querySelectorAll('input').forEach(function (input) { input.required = absent; });
        if (select.value === 'En retard') {
            form.requestSubmit ? form.requestSubmit() : form.submit();
        } else if (absent) {
            details.querySelector('input[name="matiere"]').focus();
        }
    });
});
</script>
JS]);
