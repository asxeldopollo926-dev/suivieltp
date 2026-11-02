<?php
require_once 'auth_functions.php';
require_once 'includes/layout.php';
require_once 'includes/frais.php';
require_page_access();

const FRAIS_MAX = 10000000;
const SERIE_VIDE = '_';   // jeton utilisé dans les noms de champs pour une classe sans série

$etabId = etab_id();
$combos = classe_serie_combos();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $saisie = $_POST['frais'] ?? [];
    $erreurs = 0;
    $valeurs = [];
    foreach ($combos as [$classe, $serie]) {
        $key = $serie === '' ? SERIE_VIDE : $serie;
        $m = str_replace([' ', "\u{00A0}", '.'], '', (string)($saisie[$classe][$key]['M'] ?? ''));
        $f = str_replace([' ', "\u{00A0}", '.'], '', (string)($saisie[$classe][$key]['F'] ?? ''));
        if (!ctype_digit($m) || !ctype_digit($f) || (int)$m > FRAIS_MAX || (int)$f > FRAIS_MAX) {
            $erreurs++;
            continue;
        }
        $valeurs[] = [$etabId, $classe, $serie, (int)$m, (int)$f];
    }

    if (!$combos) {
        flash('error', "Aucune classe n'est configurée pour cet établissement.");
    } elseif ($erreurs > 0) {
        flash('error', "$erreurs ligne(s) contiennent un montant invalide (nombre entier entre 0 et 10 000 000). Rien n'a été enregistré.");
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'INSERT INTO frais_scolaires (etablissement_id, classe, filiere, montant_garcon, montant_fille) VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE montant_garcon = VALUES(montant_garcon), montant_fille = VALUES(montant_fille)'
            );
            foreach ($valeurs as $v) {
                $stmt->execute($v);
            }
            $pdo->commit();
            flash('success', 'Grille des frais enregistrée. Les soldes des élèves sont recalculés automatiquement.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', log_error($e));
        }
    }
    redirect('frais_scolaires.php');
}

$grille = frais_grille($etabId);

// Effectifs par classe / série / sexe de l'établissement, pour estimer les recettes attendues
$effectifs = [];
$stmt = $pdo->prepare('SELECT classe, filiere, sexe, COUNT(*) AS n FROM eleves WHERE etablissement_id = ? GROUP BY classe, filiere, sexe');
$stmt->execute([$etabId]);
foreach ($stmt as $row) {
    $effectifs[$row['classe']][$row['filiere']][$row['sexe']] = (int)$row['n'];
}
$totalAttendu = 0;
foreach ($effectifs as $classe => $parSerie) {
    foreach ($parSerie as $serie => $parSexe) {
        foreach ($parSexe as $sexe => $n) {
            $totalAttendu += $n * montant_total(['etablissement_id' => $etabId, 'classe' => $classe, 'filiere' => $serie, 'sexe' => $sexe]);
        }
    }
}
$stmt = $pdo->prepare('SELECT COALESCE(SUM(p.montant), 0) FROM paiements p JOIN eleves e ON e.id = p.eleve_id WHERE e.etablissement_id = ?');
$stmt->execute([$etabId]);
$totalEncaisse = (float)$stmt->fetchColumn();

// Lignes encore à 0 (grille à compléter)
$aCompleter = 0;
foreach ($combos as [$classe, $serie]) {
    $g = $grille[$classe][$serie] ?? ['M' => 0, 'F' => 0];
    if ($g['M'] === 0 || $g['F'] === 0) {
        $aCompleter++;
    }
}

layout_start([
    'title'    => 'Frais scolaires',
    'subtitle' => 'Montant annuel de la scolarité par classe, ' . mb_strtolower(serie_label(), 'UTF-8') . ' et sexe — '
                  . (current_etab()['nom'] ?? '') . ' — année ' . annee_scolaire(),
]);
?>
<?php if ($aCompleter > 0): ?>
<div class="alert alert-warning">
    <?= icon('alert', 18) ?>
    <div><strong>Grille à compléter :</strong> <?= $aCompleter ?> ligne(s) ont encore un montant à 0.
        Tant qu'un montant vaut 0, le reste à payer des élèves concernés apparaît « Non défini ».</div>
</div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon"><?= icon('tag', 22) ?></div>
        <div><div class="stat-label">Recettes attendues</div><div class="stat-value"><?= e(fcfa($totalAttendu)) ?></div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon success"><?= icon('wallet', 22) ?></div>
        <div><div class="stat-label">Déjà encaissé</div><div class="stat-value"><?= e(fcfa($totalEncaisse)) ?></div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon warning"><?= icon('chart', 22) ?></div>
        <div><div class="stat-label">Taux de recouvrement</div><div class="stat-value"><?= $totalAttendu > 0 ? round($totalEncaisse / $totalAttendu * 100) : 0 ?> %</div></div>
    </div>
</div>

<?php if (!$combos): ?>
    <div class="card empty-state"><?= icon('tag', 40) ?><p>Aucune classe n'est configurée pour cet établissement.</p></div>
<?php else: ?>
<form method="POST" class="card">
    <?= csrf_field() ?>
    <div class="card-header">
        <div>
            <h2 class="card-title"><?= icon('tag') ?> Grille des frais</h2>
            <p class="card-subtitle">Montants en FCFA. Une modification s'applique immédiatement au calcul du reste à payer de tous les élèves concernés.</p>
        </div>
        <button type="submit" class="btn btn-primary"><?= icon('check', 18) ?> Enregistrer</button>
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Classe</th>
                    <th><?= e(serie_label()) ?></th>
                    <th class="num">Élèves</th>
                    <th>Garçons</th>
                    <th>Filles</th>
                </tr>
            </thead>
            <tbody>
            <?php $previous = null;
            foreach ($combos as [$classe, $serie]):
                $first = $classe !== $previous;
                $previous = $classe;
                $m = $grille[$classe][$serie]['M'] ?? 0;
                $f = $grille[$classe][$serie]['F'] ?? 0;
                $nM = $effectifs[$classe][$serie]['M'] ?? 0;
                $nF = $effectifs[$classe][$serie]['F'] ?? 0;
                $name = 'frais[' . $classe . '][' . ($serie === '' ? SERIE_VIDE : $serie) . ']';
                $label = classe_label($classe, $serie); ?>
                <tr<?= $first ? ' style="border-top:2px solid var(--border)"' : '' ?>>
                    <td class="fw-bold"><?= $first ? e($classe) : '' ?></td>
                    <td><?= $serie !== '' ? '<span class="badge badge-primary">' . e($serie) . '</span>' : '<span class="text-muted">—</span>' ?></td>
                    <td class="num text-muted"><?= $nM + $nF ?> <small>(<?= $nM ?> G / <?= $nF ?> F)</small></td>
                    <td>
                        <input class="input" type="number" min="0" max="<?= FRAIS_MAX ?>" step="500" name="<?= e($name) ?>[M]" value="<?= $m ?>"
                               aria-label="Frais garçons <?= e($label) ?>" style="max-width:160px" required>
                    </td>
                    <td>
                        <input class="input" type="number" min="0" max="<?= FRAIS_MAX ?>" step="500" name="<?= e($name) ?>[F]" value="<?= $f ?>"
                               aria-label="Frais filles <?= e($label) ?>" style="max-width:160px" required>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= icon('check', 18) ?> Enregistrer la grille</button>
    </div>
</form>
<?php endif; ?>
<?php layout_end(); ?>
