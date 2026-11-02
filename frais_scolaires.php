<?php
require_once 'auth_functions.php';
require_once 'includes/layout.php';
require_once 'includes/frais.php';
require_page_access();

const FRAIS_MAX = 10000000;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $saisie = $_POST['frais'] ?? [];
    $erreurs = 0;
    $valeurs = [];
    foreach (FRAIS_CLASSES as $classe) {
        foreach (FRAIS_FILIERES as $filiere) {
            $m = $saisie[$classe][$filiere]['M'] ?? '';
            $f = $saisie[$classe][$filiere]['F'] ?? '';
            $m = str_replace([' ', "\u{00A0}", '.'], '', (string)$m);
            $f = str_replace([' ', "\u{00A0}", '.'], '', (string)$f);
            if (!ctype_digit($m) || !ctype_digit($f) || (int)$m > FRAIS_MAX || (int)$f > FRAIS_MAX) {
                $erreurs++;
                continue;
            }
            $valeurs[] = [(int)$m, (int)$f, $classe, $filiere];
        }
    }

    if ($erreurs > 0) {
        flash('error', "$erreurs ligne(s) contiennent un montant invalide (nombre entier entre 0 et 10 000 000). Rien n'a été enregistré.");
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'INSERT INTO frais_scolaires (montant_garcon, montant_fille, classe, filiere) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE montant_garcon = VALUES(montant_garcon), montant_fille = VALUES(montant_fille)'
            );
            foreach ($valeurs as $v) {
                $stmt->execute($v);
            }
            $pdo->commit();
            flash('success', 'Grille des frais enregistrée. Les soldes des élèves sont recalculés automatiquement.');
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash('error', log_error($e));
        }
    }
    redirect('frais_scolaires.php');
}

$grille = frais_grille();

// Effectifs par classe / filière / sexe pour estimer les recettes attendues
$effectifs = [];
foreach ($pdo->query('SELECT classe, filiere, sexe, COUNT(*) AS n FROM eleves GROUP BY classe, filiere, sexe') as $row) {
    $effectifs[$row['classe']][$row['filiere']][$row['sexe']] = (int)$row['n'];
}
$totalAttendu = 0;
foreach ($effectifs as $classe => $parFiliere) {
    foreach ($parFiliere as $filiere => $parSexe) {
        foreach ($parSexe as $sexe => $n) {
            $totalAttendu += $n * montant_total(['classe' => $classe, 'filiere' => $filiere, 'sexe' => $sexe]);
        }
    }
}
$totalEncaisse = (float)$pdo->query('SELECT COALESCE(SUM(montant), 0) FROM paiements')->fetchColumn();

layout_start([
    'title'    => 'Frais scolaires',
    'subtitle' => 'Montant annuel de la scolarité par classe, filière et sexe — année ' . annee_scolaire(),
]);
?>
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
                    <th>Filière</th>
                    <th class="num">Élèves</th>
                    <th>Garçons</th>
                    <th>Filles</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach (FRAIS_CLASSES as $classe): ?>
                <?php foreach (FRAIS_FILIERES as $i => $filiere):
                    $m = $grille[$classe][$filiere]['M'] ?? frais_par_defaut($filiere, 'M');
                    $f = $grille[$classe][$filiere]['F'] ?? frais_par_defaut($filiere, 'F');
                    $nM = $effectifs[$classe][$filiere]['M'] ?? 0;
                    $nF = $effectifs[$classe][$filiere]['F'] ?? 0;
                    $name = 'frais[' . $classe . '][' . $filiere . ']'; ?>
                <tr<?= $i === 0 ? ' style="border-top:2px solid var(--border)"' : '' ?>>
                    <td class="fw-bold"><?= $i === 0 ? e($classe) : '' ?></td>
                    <td><span class="badge badge-primary"><?= e($filiere) ?></span></td>
                    <td class="num text-muted"><?= $nM + $nF ?> <small>(<?= $nM ?> G / <?= $nF ?> F)</small></td>
                    <td>
                        <input class="input" type="number" min="0" max="<?= FRAIS_MAX ?>" step="500" name="<?= e($name) ?>[M]" value="<?= $m ?>"
                               aria-label="Frais garçons <?= e("$classe $filiere") ?>" style="max-width:160px" required>
                    </td>
                    <td>
                        <input class="input" type="number" min="0" max="<?= FRAIS_MAX ?>" step="500" name="<?= e($name) ?>[F]" value="<?= $f ?>"
                               aria-label="Frais filles <?= e("$classe $filiere") ?>" style="max-width:160px" required>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= icon('check', 18) ?> Enregistrer la grille</button>
    </div>
</form>
<?php layout_end(); ?>
