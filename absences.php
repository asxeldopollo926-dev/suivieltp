<?php
require_once 'auth_functions.php';
require_once 'includes/layout.php';
require_once 'includes/notify.php';
$professeur = require_professeur();

$profId     = (int)$professeur['id'];
$etabId     = (int)$professeur['etablissement_id'];
$profSerie  = (string)$professeur['filiere'];          // '' = toutes les classes de l'établissement
$profNom    = trim($professeur['prenoms'] . ' ' . $professeur['nom']);

/** Périmètre du professeur : établissement + éventuellement sa filière. */
$scopeSql = 'e.etablissement_id = ?' . ($profSerie !== '' ? ' AND e.filiere = ?' : '');
$scopeParams = $profSerie !== '' ? [$etabId, $profSerie] : [$etabId];

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

/** La classe/série est-elle valide et dans le périmètre du professeur ? */
function classe_autorisee(string $classe, string $serie, string $profSerie): bool
{
    if (!valid_classe_serie($classe, $serie)) {
        return false;
    }
    return $profSerie === '' || $serie === $profSerie;
}

/** Classes que le professeur peut appeler. */
function classes_du_prof(string $profSerie): array
{
    if ($profSerie === '') {
        return classe_codes();
    }
    return array_values(array_filter(classe_codes(), fn($c) => classe_a_serie($c)));
}

// ---------------------------------------------------------------------------
// Traitement des formulaires
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $back = 'absences.php';

    try {
        if ($action === 'save_appel') {
            $date    = (string)($_POST['date'] ?? '');
            $classe  = (string)($_POST['classe'] ?? '');
            $serie   = $profSerie !== '' ? $profSerie : (string)($_POST['filiere'] ?? '');
            if (!classe_a_serie($classe)) {
                $serie = '';
            }
            $matiere = trim((string)($_POST['matiere'] ?? ''));
            $debut   = parse_time((string)($_POST['heure_debut'] ?? ''));
            $fin     = parse_time((string)($_POST['heure_fin'] ?? ''));
            $statuts = is_array($_POST['statut'] ?? null) ? $_POST['statut'] : [];
            $statuts = array_map(fn($v) => is_string($v) ? $v : 'invalide', $statuts);

            $back = 'absences.php?' . http_build_query(array_filter([
                'date' => valid_date($date) ? $date : null, 'classe' => $classe, 'filiere' => $serie,
            ], fn($v) => $v !== null && $v !== ''));

            // Élèves de la classe dans le périmètre du professeur
            $stmt = $pdo->prepare("SELECT e.id, e.nom, e.prenoms, e.sexe, e.classe, e.filiere, e.parent_email, e.parent_telephone, e.etablissement_id
                                   FROM eleves e WHERE $scopeSql AND e.classe = ? AND e.filiere = ?");
            $stmt->execute(array_merge($scopeParams, [$classe, $serie]));
            $classeEleves = [];
            foreach ($stmt->fetchAll() as $row) {
                $classeEleves[(int)$row['id']] = $row;
            }

            $hors = array_diff(array_map('intval', array_keys($statuts)), array_keys($classeEleves));
            $valeurs = array_unique(array_values($statuts));
            $nbAbsents = count(array_filter($statuts, fn($s) => $s === 'absent'));

            if (!valid_date($date) || $date > date('Y-m-d')) {
                flash('error', 'Date invalide (les dates futures ne sont pas acceptées).');
            } elseif (!classe_autorisee($classe, $serie, $profSerie)) {
                flash('error', "Cette classe n'existe pas ou ne fait pas partie de vos classes.");
            } elseif ($hors) {
                flash('error', "Appel refusé : certains élèves n'appartiennent pas à cette classe ou à votre périmètre.");
            } elseif (!$statuts) {
                flash('error', "Aucun élève dans cet appel.");
            } elseif (array_diff($valeurs, ['present', 'absent', 'retard'])) {
                flash('error', 'Statut invalide.');
            } elseif ($debut === false || $fin === false) {
                flash('error', 'Heures invalides (format attendu HH:MM).');
            } elseif ($nbAbsents > 0 && ($matiere === '' || !$debut || !$fin)) {
                flash('error', "Des élèves sont absents : indiquez la matière et les heures de début et de fin du cours.");
            } elseif ($debut && $fin && $debut >= $fin) {
                flash('error', "L'heure de fin doit être après l'heure de début.");
            } else {
                // Saisies existantes de ce professeur pour cette date
                $ids = array_keys($statuts);
                $in = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare("SELECT id, eleve_id, statut FROM absences WHERE professeur_id = ? AND date_absence = ? AND eleve_id IN ($in)");
                $stmt->execute(array_merge([$profId, $date], array_map('intval', $ids)));
                $existants = [];
                foreach ($stmt->fetchAll() as $row) {
                    $existants[(int)$row['eleve_id']] = $row;
                }

                $matiereDb = $matiere === '' ? null : mb_substr($matiere, 0, 100);
                $compte = ['present' => 0, 'absent' => 0, 'retard' => 0];
                $aPrevenir = [];

                $pdo->beginTransaction();
                $del = $pdo->prepare('DELETE FROM absences WHERE id = ?');
                $upd = $pdo->prepare('UPDATE absences SET statut = ?, matiere = ?, heure_debut = ?, heure_fin = ? WHERE id = ?');
                $ins = $pdo->prepare('INSERT INTO absences (eleve_id, professeur_id, date_absence, statut, matiere, heure_debut, heure_fin) VALUES (?, ?, ?, ?, ?, ?, ?)');
                foreach ($statuts as $eleveId => $choix) {
                    $eleveId = (int)$eleveId;
                    $eleve = $classeEleves[$eleveId];
                    $existant = $existants[$eleveId] ?? null;
                    $compte[$choix]++;

                    if ($choix === 'present') {
                        if ($existant) {
                            $del->execute([$existant['id']]);
                        }
                        continue;
                    }
                    if ($choix === 'absent') {
                        $statut = $eleve['sexe'] === 'F' ? 'Absente' : 'Absent';
                        $vals = [$statut, $matiereDb, $debut, $fin];
                    } else {
                        $statut = 'En retard';
                        $vals = [$statut, null, null, null];
                    }
                    if ($existant) {
                        $upd->execute(array_merge($vals, [$existant['id']]));
                    } else {
                        $ins->execute([$eleveId, $profId, $date, $vals[0], $vals[1], $vals[2], $vals[3]]);
                    }
                    // Prévenir seulement si le statut est nouveau ou a changé
                    $ancien = $existant['statut'] ?? null;
                    $ancienCat = $ancien === null ? null : ($ancien === 'En retard' ? 'retard' : (in_array($ancien, ['Absent', 'Absente'], true) ? 'absent' : 'present'));
                    if ($ancienCat !== $choix) {
                        $aPrevenir[] = [$eleve, $choix];
                    }
                }
                $pdo->commit();

                // Notifications aux parents : ne doivent jamais bloquer l'appel
                $prevenus = 0;
                try {
                    foreach ($aPrevenir as [$eleve, $choix]) {
                        $nomEleve = $eleve['prenoms'] . ' ' . mb_strtoupper($eleve['nom'], 'UTF-8');
                        $f = $eleve['sexe'] === 'F';
                        $classeTxt = classe_label($eleve['classe'], $eleve['filiere']);
                        if ($choix === 'absent') {
                            $sujet = 'Absence de votre enfant';
                            $texte = "Nous vous informons que $nomEleve (classe $classeTxt) a été marqué" . ($f ? 'e' : '') . ' absent' . ($f ? 'e' : '')
                                   . ' le ' . date_fr($date)
                                   . ($matiere !== '' ? ", en $matiere" : '')
                                   . ($debut && $fin ? ', de ' . substr($debut, 0, 5) . ' à ' . substr($fin, 0, 5) : '') . '.';
                        } else {
                            $sujet = 'Retard de votre enfant';
                            $texte = "Nous vous informons que $nomEleve (classe $classeTxt) est arrivé" . ($f ? 'e' : '') . ' en retard le ' . date_fr($date)
                                   . ($matiere !== '' ? ", en $matiere" : '') . '.';
                        }
                        $texte .= "\n\nSaisie effectuée par $profNom.";
                        if (notify_parent($eleve, 'absence', $sujet, $texte, false) > 0) {
                            $prevenus++;
                        }
                    }
                    if ($prevenus > 0) {
                        notif_process(10);
                    }
                } catch (Throwable $e) {
                    log_error($e);
                }

                flash('success', 'Appel enregistré pour la classe ' . classe_label($classe, $serie) . ' du ' . date_fr($date) . ' : '
                    . $compte['present'] . ' présent(s), ' . $compte['absent'] . ' absent(s), ' . $compte['retard'] . ' retard(s). '
                    . $prevenus . ' parent(s) prévenu(s).');
            }
        } elseif ($action === 'reset_history') {
            $stmt = $pdo->prepare('DELETE FROM absences WHERE professeur_id = ?');
            $stmt->execute([$profId]);
            flash('success', "Historique réinitialisé ({$stmt->rowCount()} enregistrement(s) supprimé(s)).");
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', log_error($e));
    }
    redirect($back);
}

// ---------------------------------------------------------------------------
// Données affichées
// ---------------------------------------------------------------------------
$date = $_GET['date'] ?? date('Y-m-d');
if (!valid_date($date) || $date > date('Y-m-d')) {
    $date = date('Y-m-d');
}
$classesProf = classes_du_prof($profSerie);
$classe = in_array($_GET['classe'] ?? '', $classesProf, true) ? $_GET['classe'] : '';
$serie = $profSerie !== '' ? $profSerie : (string)($_GET['filiere'] ?? '');
if ($classe === '' || !classe_a_serie($classe)) {
    $serie = $classe === '' ? $serie : '';
}
$classeOk = $classe !== '' && classe_autorisee($classe, $serie, $profSerie);

$eleves = [];
$prefill = ['matiere' => '', 'heure_debut' => '', 'heure_fin' => ''];
if ($classeOk) {
    $stmt = $pdo->prepare("SELECT e.id, e.nom, e.prenoms, e.sexe, a.statut AS statut_du_jour, a.matiere, a.heure_debut, a.heure_fin
        FROM eleves e
        LEFT JOIN absences a ON a.eleve_id = e.id AND a.date_absence = ? AND a.professeur_id = ?
        WHERE $scopeSql AND e.classe = ? AND e.filiere = ?
        ORDER BY e.nom, e.prenoms");
    $stmt->execute(array_merge([$date, $profId], $scopeParams, [$classe, $serie]));
    $eleves = $stmt->fetchAll();
    foreach ($eleves as $el) {
        if ($el['matiere']) {
            $prefill = ['matiere' => $el['matiere'], 'heure_debut' => substr((string)$el['heure_debut'], 0, 5), 'heure_fin' => substr((string)$el['heure_fin'], 0, 5)];
            break;
        }
    }
}

$stmt = $pdo->prepare('SELECT a.*, e.nom AS eleve_nom, e.prenoms AS eleve_prenoms, e.classe, e.filiere
    FROM absences a JOIN eleves e ON a.eleve_id = e.id
    WHERE a.professeur_id = ? AND e.etablissement_id = ?
    ORDER BY a.date_absence DESC, e.nom, e.prenoms
    LIMIT 200');
$stmt->execute([$profId, $etabId]);
$absences = $stmt->fetchAll();

function statut_badge(string $statut): string
{
    $class = in_array($statut, ['Absent', 'Absente'], true) ? 'badge-danger'
        : ($statut === 'En retard' ? 'badge-warning' : 'badge-success');
    return '<span class="badge ' . $class . '">' . e($statut) . '</span>';
}

layout_start([
    'title'    => "Faire l'appel",
    'subtitle' => (current_etab()['nom'] ?? '') . ($profSerie !== '' ? ' — ' . serie_label() . ' ' . $profSerie : ''),
    'area'     => 'prof',
    'head'     => '<style>
        .appel-choices { display: inline-flex; gap: 4px; padding: 3px; border-radius: 10px; background: #eef0f5; }
        .appel-choices input { position: absolute; opacity: 0; pointer-events: none; }
        .appel-choices label { padding: 6px 12px; border-radius: 8px; font-weight: 600; font-size: .86rem; color: var(--muted); cursor: pointer; white-space: nowrap; }
        .appel-choices input:focus-visible + label { outline: 3px solid rgba(0, 38, 136, .3); }
        .appel-choices input[value="present"]:checked + label { background: var(--success); color: #fff; }
        .appel-choices input[value="absent"]:checked + label { background: var(--danger); color: #fff; }
        .appel-choices input[value="retard"]:checked + label { background: var(--warning); color: #fff; }
        .appel-summary { display: flex; flex-wrap: wrap; gap: 8px; }
    </style>',
]);
?>
<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title"><?= icon('calendar') ?> Choisir la classe et la date</h2>
            <p class="card-subtitle">Tous les élèves sont présents par défaut : cochez seulement les absents et les retardataires.</p>
        </div>
    </div>
    <form method="GET" class="filters">
        <input type="date" name="date" value="<?= e($date) ?>" max="<?= date('Y-m-d') ?>" aria-label="Date de l'appel" required>
        <?php if ($profSerie === ''): ?>
            <?= classe_serie_selects($classe, $serie, ['wrap' => false]) ?>
        <?php else: ?>
            <select name="classe" aria-label="Classe" required>
                <option value="">Choisir la classe…</option>
                <?php foreach ($classesProf as $c): ?>
                    <option value="<?= e($c) ?>" <?= $c === $classe ? 'selected' : '' ?>><?= e(classe_label($c, $profSerie)) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary"><?= icon('users', 16) ?> Afficher la classe</button>
    </form>
</section>

<?php if ($classe !== '' && !$classeOk): ?>
    <div class="alert alert-warning"><?= icon('alert', 18) ?><div>Choisissez la <?= e(mb_strtolower(serie_label(), 'UTF-8')) ?> de la classe <?= e($classe) ?>.</div></div>
<?php elseif ($classeOk): ?>
<form method="POST" class="card" id="appelForm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_appel">
    <input type="hidden" name="date" value="<?= e($date) ?>">
    <input type="hidden" name="classe" value="<?= e($classe) ?>">
    <input type="hidden" name="filiere" value="<?= e($serie) ?>">
    <div class="card-header">
        <div>
            <h2 class="card-title"><?= icon('users') ?> Classe <?= e(classe_label($classe, $serie)) ?> — <?= e(date_fr($date)) ?></h2>
            <p class="card-subtitle"><?= count($eleves) ?> élève(s)</p>
        </div>
        <div class="appel-summary" aria-live="polite">
            <span class="badge badge-success" data-count="present">0 présent(s)</span>
            <span class="badge badge-danger" data-count="absent">0 absent(s)</span>
            <span class="badge badge-warning" data-count="retard">0 retard(s)</span>
        </div>
    </div>

    <?php if (!$eleves): ?>
        <div class="empty-state"><?= icon('users', 40) ?><p>Aucun élève dans cette classe.</p></div>
    <?php else: ?>
    <div class="form-grid mb-2">
        <div class="form-group">
            <label for="matiere">Matière / cours</label>
            <input type="text" id="matiere" name="matiere" maxlength="100" value="<?= e($prefill['matiere']) ?>" placeholder="Ex. Mathématiques">
            <p class="form-help">Obligatoire s'il y a des absents.</p>
        </div>
        <div class="form-group">
            <label for="heure_debut">Heure de début</label>
            <input type="time" id="heure_debut" name="heure_debut" value="<?= e($prefill['heure_debut']) ?>">
        </div>
        <div class="form-group">
            <label for="heure_fin">Heure de fin</label>
            <input type="time" id="heure_fin" name="heure_fin" value="<?= e($prefill['heure_fin']) ?>">
        </div>
    </div>

    <div class="btn-group mb-2">
        <button type="button" class="btn btn-sm btn-outline" data-all="present"><?= icon('check', 16) ?> Tous présents</button>
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>ID</th><th>Élève</th><th>Saisie actuelle</th><th>Statut</th></tr></thead>
            <tbody>
            <?php foreach ($eleves as $el):
                $f = $el['sexe'] === 'F';
                $cur = $el['statut_du_jour'];
                $choix = $cur === 'En retard' ? 'retard' : (in_array($cur, ['Absent', 'Absente'], true) ? 'absent' : 'present');
                $id = (int)$el['id'];
            ?>
                <tr>
                    <td><?= $id ?></td>
                    <td><span class="fw-bold"><?= e(mb_strtoupper($el['nom'], 'UTF-8')) ?></span> <?= e($el['prenoms']) ?></td>
                    <td><?= $cur ? statut_badge($cur) : '<span class="text-muted">—</span>' ?></td>
                    <td>
                        <div class="appel-choices" role="radiogroup" aria-label="Statut de <?= e($el['prenoms'] . ' ' . $el['nom']) ?>">
                            <?php foreach (['present' => $f ? 'Présente' : 'Présent', 'absent' => $f ? 'Absente' : 'Absent', 'retard' => 'Retard'] as $val => $lab): ?>
                                <input type="radio" name="statut[<?= $id ?>]" id="s<?= $id ?>_<?= $val ?>" value="<?= $val ?>" <?= $choix === $val ? 'checked' : '' ?>>
                                <label for="s<?= $id ?>_<?= $val ?>"><?= e($lab) ?></label>
                            <?php endforeach; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= icon('check', 18) ?> Enregistrer l'appel</button>
    </div>
    <?php endif; ?>
</form>
<?php endif; ?>

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
                    <td><?= e(classe_label($a['classe'], $a['filiere'])) ?></td>
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
(function () {
    var form = document.getElementById('appelForm');
    if (!form) { return; }
    function refresh() {
        var counts = { present: 0, absent: 0, retard: 0 };
        form.querySelectorAll('input[type=radio]:checked').forEach(function (r) { counts[r.value]++; });
        var labels = { present: ' présent(s)', absent: ' absent(s)', retard: ' retard(s)' };
        Object.keys(counts).forEach(function (k) {
            var badge = form.querySelector('[data-count="' + k + '"]');
            if (badge) { badge.textContent = counts[k] + labels[k]; }
        });
        // Matière et heures obligatoires dès qu'il y a un absent
        ['matiere', 'heure_debut', 'heure_fin'].forEach(function (name) {
            var input = form.querySelector('[name="' + name + '"]');
            if (input) { input.required = counts.absent > 0; }
        });
    }
    form.addEventListener('change', refresh);
    form.querySelectorAll('[data-all]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            form.querySelectorAll('input[type=radio][value="' + btn.getAttribute('data-all') + '"]').forEach(function (r) { r.checked = true; });
            refresh();
        });
    });
    refresh();
})();
</script>
JS]);
