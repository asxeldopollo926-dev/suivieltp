<?php
/**
 * Frais de scolarité : la grille est stockée dans la table `frais_scolaires`
 * (un montant garçon et un montant fille par classe et filière) et se modifie
 * depuis la page frais.php. Utiliser montant_total($eleve) partout.
 */

const FRAIS_CLASSES  = ['2nde', '1ere', 'Tle'];
const FRAIS_FILIERES = ['DWM', 'EA', 'COM', 'IMI', 'F3', 'PM'];

/** Montants historiques, utilisés pour initialiser la grille. */
function frais_par_defaut(string $filiere, string $sexe): int
{
    if ($filiere === 'COM') {
        return 53000;
    }
    return $sexe === 'M' ? 120000 : 80000;
}

/** Grille complète : [classe][filiere] => ['M' => int, 'F' => int]. Mise en cache par requête. */
function frais_grille(bool $refresh = false): array
{
    global $pdo;
    static $grille = null;
    if ($grille === null || $refresh) {
        $grille = [];
        foreach ($pdo->query('SELECT classe, filiere, montant_garcon, montant_fille FROM frais_scolaires') as $row) {
            $grille[$row['classe']][$row['filiere']] = [
                'M' => (int)$row['montant_garcon'],
                'F' => (int)$row['montant_fille'],
            ];
        }
    }
    return $grille;
}

/** Montant annuel dû par un élève (tableau contenant au moins classe, filiere, sexe). */
function montant_total(array $eleve): int
{
    $sexe = ($eleve['sexe'] ?? 'M') === 'F' ? 'F' : 'M';
    $grille = frais_grille();
    return $grille[$eleve['classe'] ?? ''][$eleve['filiere'] ?? ''][$sexe]
        ?? frais_par_defaut($eleve['filiere'] ?? '', $sexe);
}

/** Année scolaire d'une date (rentrée en septembre), ex. "2025-2026". */
function annee_scolaire(?string $date = null): string
{
    $ts = $date ? strtotime($date) : time();
    $year = (int)date('Y', $ts);
    $start = (int)date('n', $ts) >= 9 ? $year : $year - 1;
    return $start . '-' . ($start + 1);
}
