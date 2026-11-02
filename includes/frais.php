<?php
/**
 * Frais de scolarité : la grille est stockée dans la table `frais_scolaires`
 * (un montant garçon et un montant fille par établissement, classe et série/filière)
 * et se modifie depuis la page frais_scolaires.php. Utiliser montant_total($eleve) partout.
 */
require_once __DIR__ . '/etablissement.php';

/** Grille d'un établissement : [classe][serie] => ['M' => int, 'F' => int]. Mise en cache par requête. */
function frais_grille(?int $etabId = null, bool $refresh = false): array
{
    global $pdo;
    static $grilles = [];
    $etabId = $etabId ?? etab_id();
    if (!isset($grilles[$etabId]) || $refresh) {
        $stmt = $pdo->prepare('SELECT classe, filiere, montant_garcon, montant_fille FROM frais_scolaires WHERE etablissement_id = ?');
        $stmt->execute([$etabId]);
        $grilles[$etabId] = [];
        foreach ($stmt as $row) {
            $grilles[$etabId][$row['classe']][$row['filiere']] = [
                'M' => (int)$row['montant_garcon'],
                'F' => (int)$row['montant_fille'],
            ];
        }
    }
    return $grilles[$etabId];
}

/**
 * Montant annuel dû par un élève (tableau contenant classe, filiere, sexe et idéalement etablissement_id).
 * Retourne 0 si aucun montant n'a été défini pour sa classe.
 */
function montant_total(array $eleve): int
{
    $etabId = isset($eleve['etablissement_id']) ? (int)$eleve['etablissement_id'] : etab_id();
    $sexe = ($eleve['sexe'] ?? 'M') === 'F' ? 'F' : 'M';
    return frais_grille($etabId)[$eleve['classe'] ?? ''][$eleve['filiere'] ?? ''][$sexe] ?? 0;
}

/** Année scolaire d'une date (rentrée en septembre), ex. "2025-2026". */
function annee_scolaire(?string $date = null): string
{
    $ts = $date ? strtotime($date) : time();
    $year = (int)date('Y', $ts);
    $start = (int)date('n', $ts) >= 9 ? $year : $year - 1;
    return $start . '-' . ($start + 1);
}
