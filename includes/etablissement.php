<?php
/**
 * Multi-établissements : un même site gère des lycées et des collèges dont les données sont séparées.
 *
 * Chaque établissement possède ses classes (etab_classes) et ses séries / filières (etab_series).
 * Une classe « sans série » (ex. 6e à 3e au collège) a une filière vide ('').
 * La colonne technique s'appelle toujours `filiere` ; elle s'affiche « Filière » (lycée) ou « Série » (collège).
 *
 * Règle d'or : toute requête sur eleves / professeurs / bulletins / frais_scolaires doit filtrer
 * par etablissement_id = etab_id().
 */

const ETAB_TYPES = [
    'lycee'   => ['name' => 'Lycée',   'serie_label' => 'Filière', 'serie_label_pluriel' => 'Filières'],
    'college' => ['name' => 'Collège', 'serie_label' => 'Série',   'serie_label_pluriel' => 'Séries'],
];

/** Structure créée automatiquement pour un nouvel établissement. */
const ETAB_STRUCTURE_DEFAUT = [
    'lycee' => [
        'classes' => [['2nde', 1], ['1ere', 1], ['Tle', 1]],
        'series'  => [['DWM', 'Développement Web et Mobile'], ['EA', 'Électricité Auto'], ['COM', 'Comptabilité'],
                      ['IMI', 'Installation et Maintenance Informatique'], ['F3', 'Électrotechnique'], ['PM', 'Productique Mécanique']],
    ],
    'college' => [
        'classes' => [['6e', 0], ['5e', 0], ['4e', 0], ['3e', 0], ['2nde', 1], ['1ere', 1], ['Tle', 1]],
        'series'  => [['A1', 'Lettres - Langues'], ['A2', 'Lettres - Sciences humaines'], ['B', 'Sciences économiques et sociales'],
                      ['C', 'Mathématiques et sciences physiques'], ['D', 'Mathématiques et sciences de la nature']],
    ],
];

// ---------------------------------------------------------------------------
// Création / initialisation
// ---------------------------------------------------------------------------
function etab_seed_structure(PDO $pdo, int $etabId, string $type): void
{
    $def = ETAB_STRUCTURE_DEFAUT[$type] ?? ETAB_STRUCTURE_DEFAUT['lycee'];
    $c = $pdo->prepare('INSERT IGNORE INTO etab_classes (etablissement_id, code, ordre, avec_serie) VALUES (?, ?, ?, ?)');
    foreach ($def['classes'] as $i => [$code, $avecSerie]) {
        $c->execute([$etabId, $code, $i + 1, $avecSerie]);
    }
    $s = $pdo->prepare('INSERT IGNORE INTO etab_series (etablissement_id, code, libelle, ordre) VALUES (?, ?, ?, ?)');
    foreach ($def['series'] as $i => [$code, $libelle]) {
        $s->execute([$etabId, $code, $libelle, $i + 1]);
    }
    etab_cache_clear();
}

/** Montants historiques du lycée (utilisés pour initialiser sa grille de frais). */
function frais_par_defaut(string $filiere, string $sexe, string $type = 'lycee'): int
{
    if ($type !== 'lycee') {
        return 0; // collège : montants à saisir par le comptable
    }
    if ($filiere === 'COM') {
        return 53000;
    }
    return $sexe === 'M' ? 120000 : 80000;
}

/** Ajoute les lignes de frais manquantes (une par combinaison classe/série). Retourne le nombre ajouté. */
function etab_seed_frais(PDO $pdo, int $etabId, string $type): int
{
    $ins = $pdo->prepare('INSERT IGNORE INTO frais_scolaires (etablissement_id, classe, filiere, montant_garcon, montant_fille) VALUES (?, ?, ?, ?, ?)');
    $n = 0;
    foreach (classe_serie_combos($etabId) as [$classe, $serie]) {
        $ins->execute([$etabId, $classe, $serie, frais_par_defaut($serie, 'M', $type), frais_par_defaut($serie, 'F', $type)]);
        $n += $ins->rowCount();
    }
    return $n;
}

// ---------------------------------------------------------------------------
// Établissement courant
// ---------------------------------------------------------------------------
/** Force l'établissement pour la requête en cours (espace professeur / parents). */
function set_request_etab(int $etabId): void
{
    $GLOBALS['__request_etab_id'] = $etabId;
}

function etablissement(int $id): ?array
{
    global $pdo;
    static $cache = [];
    if (!array_key_exists($id, $cache)) {
        $stmt = $pdo->prepare('SELECT * FROM etablissements WHERE id = ?');
        $stmt->execute([$id]);
        $cache[$id] = $stmt->fetch() ?: null;
    }
    return $cache[$id];
}

/** @return array<int, array> */
function etablissements_list(?string $type = null): array
{
    global $pdo;
    if ($type === null) {
        return $pdo->query("SELECT * FROM etablissements ORDER BY FIELD(type, 'lycee', 'college'), nom")->fetchAll();
    }
    $stmt = $pdo->prepare('SELECT * FROM etablissements WHERE type = ? ORDER BY nom');
    $stmt->execute([$type]);
    return $stmt->fetchAll();
}

function etab_id(): int
{
    return (int)($GLOBALS['__request_etab_id'] ?? $_SESSION['etab_id'] ?? 0);
}

function current_etab(): ?array
{
    $id = etab_id();
    return $id ? etablissement($id) : null;
}

function etab_type(): string
{
    return current_etab()['type'] ?? 'lycee';
}

function is_college(): bool
{
    return etab_type() === 'college';
}

/** « Filière » au lycée, « Série » au collège. */
function serie_label(bool $pluriel = false): string
{
    return ETAB_TYPES[etab_type()][$pluriel ? 'serie_label_pluriel' : 'serie_label'];
}

function etab_type_name(?string $type = null): string
{
    return ETAB_TYPES[$type ?? etab_type()]['name'] ?? '';
}

// ---------------------------------------------------------------------------
// Classes et séries
// ---------------------------------------------------------------------------
function etab_cache_clear(): void
{
    $GLOBALS['__etab_struct_cache'] = [];
}

function etab_structure(?int $etabId = null): array
{
    global $pdo;
    $etabId = $etabId ?? etab_id();
    $cache = &$GLOBALS['__etab_struct_cache'];
    if (!isset($cache[$etabId])) {
        $c = $pdo->prepare('SELECT code, avec_serie FROM etab_classes WHERE etablissement_id = ? ORDER BY ordre, id');
        $c->execute([$etabId]);
        $s = $pdo->prepare('SELECT code, libelle FROM etab_series WHERE etablissement_id = ? ORDER BY ordre, id');
        $s->execute([$etabId]);
        $cache[$etabId] = ['classes' => $c->fetchAll(), 'series' => $s->fetchAll()];
    }
    return $cache[$etabId];
}

/** @return string[] codes des classes, dans l'ordre (ex. 6e, 5e, … Tle) */
function classe_codes(?int $etabId = null): array
{
    return array_column(etab_structure($etabId)['classes'], 'code');
}

/** @return string[] codes des séries / filières */
function serie_codes(?int $etabId = null): array
{
    return array_column(etab_structure($etabId)['series'], 'code');
}

/** @return array<string,string> code => libellé */
function series_libelles(?int $etabId = null): array
{
    return array_column(etab_structure($etabId)['series'], 'libelle', 'code');
}

/** @return string[] classes qui n'ont pas de série (ex. 6e à 3e) */
function classes_sans_serie(?int $etabId = null): array
{
    $out = [];
    foreach (etab_structure($etabId)['classes'] as $c) {
        if (!$c['avec_serie']) {
            $out[] = $c['code'];
        }
    }
    return $out;
}

function classe_a_serie(string $classe, ?int $etabId = null): bool
{
    foreach (etab_structure($etabId)['classes'] as $c) {
        if ($c['code'] === $classe) {
            return (bool)$c['avec_serie'];
        }
    }
    return false;
}

/** La combinaison classe / série est-elle valide pour l'établissement ? */
function valid_classe_serie(string $classe, string $serie, ?int $etabId = null): bool
{
    if (!in_array($classe, classe_codes($etabId), true)) {
        return false;
    }
    return classe_a_serie($classe, $etabId)
        ? in_array($serie, serie_codes($etabId), true)
        : $serie === '';
}

/** Toutes les combinaisons [classe, série] existantes (série '' pour les classes sans série). */
function classe_serie_combos(?int $etabId = null): array
{
    $combos = [];
    foreach (etab_structure($etabId)['classes'] as $c) {
        if ($c['avec_serie']) {
            foreach (serie_codes($etabId) as $s) {
                $combos[] = [$c['code'], $s];
            }
        } else {
            $combos[] = [$c['code'], ''];
        }
    }
    return $combos;
}

/** Libellé lisible : « Tle D », « 6e ». */
function classe_label(string $classe, string $serie = ''): string
{
    return trim($classe . ' ' . $serie);
}

/** Expression SQL ORDER BY respectant l'ordre des classes de l'établissement. */
function sql_order_classe(string $column = 'classe'): string
{
    global $pdo;
    $codes = classe_codes();
    if (!$codes) {
        return $column;
    }
    return 'FIELD(' . $column . ', ' . implode(', ', array_map([$pdo, 'quote'], $codes)) . ')';
}

/**
 * Deux listes déroulantes liées « Classe » et « Série/Filière ».
 * La série est désactivée automatiquement pour les classes qui n'en ont pas (assets/js/app.js).
 *   $opt['filter']    : true = listes de filtrage (« Toutes les classes », rien d'obligatoire, envoi auto)
 *   $opt['wrap']      : true (défaut hors filtre) = chaque liste dans un .form-group avec son libellé
 *   $opt['names']     : noms des champs, défaut ['classe', 'filiere']
 *   $opt['id_prefix'] : préfixe des id HTML (si plusieurs formulaires sur la page)
 */
function classe_serie_selects(string $classe = '', string $serie = '', array $opt = []): string
{
    $filter = !empty($opt['filter']);
    $wrap = $opt['wrap'] ?? !$filter;
    $prefix = $opt['id_prefix'] ?? '';
    [$nameC, $nameS] = $opt['names'] ?? ['classe', 'filiere'];
    $attrs = $filter ? ' data-autosubmit' : ' required';
    $labelS = serie_label();

    $selC = '<select name="' . e($nameC) . '" id="' . e($prefix . $nameC) . '" aria-label="Classe" data-classe-select data-sans-serie="'
          . e(json_encode(classes_sans_serie())) . '"' . $attrs . '>'
          . '<option value="">' . ($filter ? 'Toutes les classes' : 'Choisir…') . '</option>';
    foreach (classe_codes() as $c) {
        $selC .= '<option value="' . e($c) . '"' . ($c === $classe ? ' selected' : '') . '>' . e($c) . '</option>';
    }
    $selC .= '</select>';

    $placeholder = $filter ? 'Toutes les ' . mb_strtolower(serie_label(true), 'UTF-8') : 'Choisir…';
    $selS = '<select name="' . e($nameS) . '" id="' . e($prefix . $nameS) . '" aria-label="' . e($labelS) . '" data-serie-select' . $attrs . '>'
          . '<option value="">' . e($placeholder) . '</option>';
    foreach (series_libelles() as $code => $lib) {
        $selS .= '<option value="' . e($code) . '"' . ($code === $serie ? ' selected' : '') . ' title="' . e($lib) . '">' . e($code) . '</option>';
    }
    $selS .= '</select>';

    if (!$wrap) {
        return $selC . $selS;
    }
    return '<div class="form-group"><label for="' . e($prefix . $nameC) . '">Classe</label>' . $selC . '</div>'
         . '<div class="form-group"><label for="' . e($prefix . $nameS) . '">' . e($labelS) . '</label>' . $selS . '</div>';
}

