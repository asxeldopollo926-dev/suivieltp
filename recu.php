<?php
/**
 * Reçu de paiement imprimable (Imprimer -> « Enregistrer au format PDF » pour obtenir un PDF).
 * Accès : personnel autorisé aux paiements, ou parent connecté pour son propre enfant.
 */
require_once 'auth_functions.php';
require_once 'includes/layout.php';
require_once 'includes/frais.php';

/** Écrit un nombre entier positif en toutes lettres (orthographe traditionnelle). */
function nombre_en_lettres(int $n): string
{
    static $unites = ['zéro', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf', 'dix',
        'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize', 'dix-sept', 'dix-huit', 'dix-neuf'];
    static $dizaines = [2 => 'vingt', 3 => 'trente', 4 => 'quarante', 5 => 'cinquante', 6 => 'soixante', 8 => 'quatre-vingt'];

    $moinsDeCent = function (int $n, bool $final) use ($unites, $dizaines): string {
        if ($n < 20) {
            return $unites[$n];
        }
        $d = intdiv($n, 10);
        $u = $n % 10;
        if ($d === 7 || $d === 9) {
            $base = $d === 7 ? 'soixante' : 'quatre-vingt';
            return ($d === 7 && $u === 1) ? 'soixante et onze' : $base . '-' . $unites[10 + $u];
        }
        if ($u === 0) {
            return $d === 8 && $final ? 'quatre-vingts' : $dizaines[$d];
        }
        return $dizaines[$d] . ($u === 1 && $d !== 8 ? ' et un' : '-' . $unites[$u]);
    };
    $moinsDeMille = function (int $n, bool $final) use ($moinsDeCent, $unites): string {
        $c = intdiv($n, 100);
        $reste = $n % 100;
        if ($c === 0) {
            return $moinsDeCent($reste, $final);
        }
        $cent = $c === 1 ? 'cent' : $unites[$c] . ' cent';
        if ($reste === 0) {
            return $cent . ($c > 1 && $final ? 's' : '');
        }
        return $cent . ' ' . $moinsDeCent($reste, $final);
    };

    if ($n === 0) {
        return 'zéro';
    }
    $parts = [];
    $millions = intdiv($n, 1000000);
    $milliers = intdiv($n % 1000000, 1000);
    $reste = $n % 1000;
    if ($millions > 0) {
        $parts[] = $moinsDeMille($millions, true) . ' million' . ($millions > 1 ? 's' : '');
    }
    if ($milliers > 0) {
        $parts[] = $milliers === 1 ? 'mille' : $moinsDeMille($milliers, false) . ' mille';
    }
    if ($reste > 0) {
        $parts[] = $moinsDeMille($reste, true);
    }
    return implode(' ', $parts);
}

// ---------------------------------------------------------------------------
$paiementId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$stmt = $pdo->prepare('SELECT p.*, e.nom, e.prenoms, e.sexe, e.classe, e.filiere
                       FROM paiements p JOIN eleves e ON e.id = p.eleve_id WHERE p.id = ?');
$stmt->execute([$paiementId]);
$paiement = $stmt->fetch();

$isStaff = canAccessPage('recu.php');
$isParent = $paiement && !empty($_SESSION['eleve_id']) && (int)$_SESSION['eleve_id'] === (int)$paiement['eleve_id'];
if (!$isStaff && !$isParent) {
    if (!$paiement && !isLoggedIn() && empty($_SESSION['eleve_id'])) {
        redirect('bienvenue.html');
    }
    http_response_code($paiement ? 403 : 404);
    exit($paiement ? 'Accès refusé.' : 'Reçu introuvable.');
}
if (!$paiement) {
    http_response_code(404);
    exit('Reçu introuvable.');
}

// Situation du compte au moment de ce paiement (paiements antérieurs ou du même jour enregistrés avant)
$stmt = $pdo->prepare('SELECT COALESCE(SUM(montant), 0) FROM paiements
                       WHERE eleve_id = ? AND (date_paiement < ? OR (date_paiement = ? AND id <= ?))');
$stmt->execute([$paiement['eleve_id'], $paiement['date_paiement'], $paiement['date_paiement'], $paiement['id']]);
$cumul = (int)$stmt->fetchColumn();
$du = montant_total($paiement);
$reste = max(0, $du - $cumul);

$montant = (int)round((float)$paiement['montant']);
$numero = 'REC-' . date('Y', strtotime($paiement['date_paiement'])) . '-' . str_pad((string)$paiement['id'], 6, '0', STR_PAD_LEFT);
$retour = $isStaff ? 'paiement_details.php?id=' . (int)$paiement['eleve_id'] : 'parents.php';
$nouveau = isset($_GET['nouveau']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reçu <?= e($numero) ?> - SuiviLTP</title>
    <link rel="icon" href="assets/logo.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/app.css?v=2">
    <style>
        body { background: var(--bg); }
        .toolbar { max-width: 760px; margin: 24px auto 0; padding: 0 16px; display: flex; flex-wrap: wrap; gap: 10px; justify-content: space-between; align-items: center; }
        .receipt { max-width: 760px; margin: 16px auto 40px; background: #fff; border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding: 36px 40px; }
        .receipt-head { display: flex; align-items: center; gap: 16px; padding-bottom: 18px; border-bottom: 3px solid var(--primary); }
        .receipt-head img { width: 64px; height: 64px; }
        .receipt-head h1 { font-size: 1.15rem; color: var(--primary); }
        .receipt-head p { margin: 2px 0 0; color: var(--muted); font-size: .88rem; }
        .receipt-title { display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; gap: 8px; margin: 22px 0 18px; }
        .receipt-title h2 { font-size: 1.35rem; letter-spacing: 1px; }
        .receipt-title .code { color: var(--primary); font-size: 1rem; }
        .amount-box { margin: 20px 0; padding: 16px 18px; border: 2px dashed var(--primary); border-radius: var(--radius-sm); background: var(--primary-soft); }
        .amount-box .figures { font-size: 1.6rem; font-weight: 800; color: var(--primary); }
        .amount-box .words { margin-top: 4px; font-style: italic; }
        .summary { width: 100%; border-collapse: collapse; margin-top: 8px; }
        .summary td { padding: 8px 0; border-bottom: 1px solid var(--border); }
        .summary td:last-child { text-align: right; font-weight: 700; }
        .signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-top: 40px; }
        .signatures div { min-height: 90px; border-top: 1px solid #98a2b3; padding-top: 6px; text-align: center; color: var(--muted); font-size: .88rem; }
        .receipt-foot { margin-top: 28px; color: var(--muted); font-size: .78rem; text-align: center; }
        @media (max-width: 600px) { .receipt { padding: 22px 18px; border-radius: 0; } .signatures { grid-template-columns: 1fr; } }
        @media print {
            @page { size: A5 portrait; margin: 10mm; }
            body { background: #fff; }
            .toolbar { display: none !important; }
            .receipt { margin: 0; padding: 0; border: 0; box-shadow: none; max-width: none; }
            .amount-box { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <a href="<?= e($retour) ?>" class="btn btn-secondary"><?= icon('arrow-left', 16) ?> Retour</a>
        <?php if ($nouveau): ?><span class="badge badge-success"><?= icon('check', 14) ?> Paiement enregistré</span><?php endif; ?>
        <button type="button" class="btn btn-primary" onclick="window.print()"><?= icon('printer', 16) ?> Imprimer / PDF</button>
    </div>

    <main class="receipt">
        <header class="receipt-head">
            <img src="assets/logo.svg" alt="">
            <div>
                <h1><?= e(SCHOOL_NAME) ?></h1>
                <p><?= e(SCHOOL_CITY) ?> — <?= e(SCHOOL_CONTACT) ?></p>
                <p>Année scolaire <?= e(annee_scolaire($paiement['date_paiement'])) ?></p>
            </div>
        </header>

        <div class="receipt-title">
            <h2>REÇU DE PAIEMENT</h2>
            <span class="code">N° <?= e($numero) ?></span>
        </div>

        <dl class="info-list">
            <div><dt>Élève</dt><dd><?= e(mb_strtoupper($paiement['nom'], 'UTF-8') . ' ' . $paiement['prenoms']) ?></dd></div>
            <div><dt>Matricule (ID)</dt><dd><?= (int)$paiement['eleve_id'] ?></dd></div>
            <div><dt>Classe</dt><dd><?= e($paiement['classe'] . ' ' . $paiement['filiere']) ?></dd></div>
            <div><dt>Date du paiement</dt><dd><?= e(date_fr($paiement['date_paiement'])) ?></dd></div>
            <div><dt>Mode de paiement</dt><dd><?= e($paiement['mode_paiement']) ?></dd></div>
        </dl>

        <div class="amount-box">
            <div class="text-muted">Montant reçu</div>
            <div class="figures"><?= e(fcfa($montant)) ?></div>
            <div class="words">Arrêté le présent reçu à la somme de : <strong><?= e(ucfirst(nombre_en_lettres($montant))) ?> francs CFA</strong>.</div>
        </div>

        <table class="summary">
            <tr><td>Frais de scolarité annuels</td><td><?= e(fcfa($du)) ?></td></tr>
            <tr><td>Total versé à cette date (ce paiement inclus)</td><td><?= e(fcfa($cumul)) ?></td></tr>
            <tr><td>Reste à payer après ce paiement</td><td class="<?= $reste === 0 ? 'text-success' : 'text-danger' ?>"><?= $reste === 0 ? 'Soldé' : e(fcfa($reste)) ?></td></tr>
        </table>

        <div class="signatures">
            <div>Signature du parent / déposant</div>
            <div>Le comptable (signature et cachet)</div>
        </div>

        <p class="receipt-foot">Reçu édité le <?= date('d/m/Y à H:i') ?> via SuiviLTP. Toute rature ou surcharge rend ce reçu invalide.</p>
    </main>
</body>
</html>
