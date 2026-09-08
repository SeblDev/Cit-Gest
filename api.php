<?php
/**
 * Cit-Gest - API Backend PHP & Base SQLite v4.3 (sécurisée)
 *
 * Changements par rapport à la v4.2 :
 * - Authentification serveur par session (plus de vérif. côté client)
 * - Mots de passe hachés (password_hash / password_verify)
 * - Whitelist stricte des colonnes autorisées par table
 * - CORS restreint au domaine de l'application
 * - Création de ticket public : INSERT strict (pas d'écrasement possible),
 *   ID généré côté serveur, statut forcé
 * - Toutes les autres actions nécessitent une session valide
 */

session_start();

// ---------------------------------------------------------------------
// CORS : remplace cette valeur par le domaine réel de ton appli
// (ex: 'https://citgest.gimont.fr'). Pour l'instant : IP du serveur.
// ---------------------------------------------------------------------
$allowedOrigin = 'http://51.210.176.106';

header('Content-Type: application/json; charset=utf-8');
if (isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] === $allowedOrigin) {
    header("Access-Control-Allow-Origin: $allowedOrigin");
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// ---------------------------------------------------------------------
// Base de données : stockée HORS du dossier public servi par Apache.
// Adapte ce chemin à ton arborescence réelle sur le serveur LAMP.
// Exemple : si api.php est dans /var/www/citgest/public/,
// on stocke la base dans /var/www/citgest/data/database.sqlite
// ---------------------------------------------------------------------
$dbFile = dirname(__DIR__) . '/citgest_data/database.sqlite';

// Colonnes autorisées par table (protège save_item / save_generic contre
// l'injection de noms de colonnes arbitraires)
$ALLOWED_COLUMNS = [
    'communes'      => ['id', 'name', 'respTech', 'phone'],
    'categories'    => ['id', 'name', 'emoji'],
    'patrimoine'    => ['id','commune','name','type_bien','num_inventaire','address',
                         'ref_cadastrale','statut_propriete','valeur_acquisition','date_achat',
                         'subventions','assurance_ref','assurance_montant','surface_sol',
                         'surface_utile','nb_pieces','num_serie','etat_general','diagnostics',
                         'elecMeter','waterMeter','gasMeter','controles_reglementaires',
                         'contrats_entretien','notes','documents'],
    'utilisateurs'  => ['id','username','password','name','email','role','commune','skill','status','avatar'],
    'equipements'   => ['id','name','owner','status','desc','icon'],
    'tickets'       => ['id','commune','category','title','location','priority','status',
                         'requester','contact','description','dateCreated','assignedAgentId'],
    'releves_consommation' => ['id','patrimoine_id','type','valeur','dateReleve','agentName','remarque'],
];

try {
    $pdo = new PDO("sqlite:" . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    initDatabase($pdo);

    $action = $_GET['action'] ?? 'get_all';
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    switch ($action) {

        // ----- AUTHENTIFICATION (public) -----
        case 'login':
            $username = $input['username'] ?? '';
            $password = $input['password'] ?? '';
            $stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            if ($user && password_verify($password, $user['password'])) {
                unset($user['password']);
                $_SESSION['user'] = $user;
                echo json_encode(['success' => true, 'user' => $user]);
            } else {
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'Identifiants incorrects']);
            }
            break;

        case 'logout':
            $_SESSION = [];
            session_destroy();
            echo json_encode(['success' => true]);
            break;

        case 'me':
            echo json_encode(['success' => true, 'user' => currentUser()]);
            break;

        // ----- LECTURE COMPLÈTE (agents/mairie uniquement) -----
        case 'get_all':
            requireAuth();
            echo json_encode([
                'success' => true,
                'tickets' => getTicketsWithComments($pdo),
                'categories' => getTableData($pdo, 'categories'),
                'patrimoine' => getTableData($pdo, 'patrimoine'),
                'communes' => getTableData($pdo, 'communes'),
                'users' => getTableData($pdo, 'utilisateurs', true), // sans mots de passe
                'equipment' => getTableData($pdo, 'equipements'),
                'releves' => getTableData($pdo, 'releves_consommation')
            ]);
            break;

        // ----- TICKETS -----
        case 'save_ticket':
            if (empty($input)) {
                echo json_encode(['success' => false, 'message' => 'Données invalides']);
                break;
            }
            $user = currentUser();
            if ($user) {
                // Agent/mairie connecté : peut créer ou modifier (statut, agent assigné, etc.)
                saveTicket($pdo, $input);
            } else {
                // Citoyen anonyme : création uniquement, champs sensibles forcés côté serveur
                $input['id'] = 'INT-' . strtoupper(bin2hex(random_bytes(3)));
                $input['status'] = 'NOUVELLE';
                $input['assignedAgentId'] = null;
                insertTicketOnly($pdo, $input);
            }
            echo json_encode(['success' => true, 'message' => 'Ticket enregistré']);
            break;

        case 'add_comment':
            requireAuth();
            if (!empty($input['ticket_id']) && !empty($input['text'])) {
                $u = currentUser();
                addComment($pdo, $input['ticket_id'], $u['name'], $input['text']);
                echo json_encode(['success' => true, 'message' => 'Commentaire ajouté']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Commentaire incomplet']);
            }
            break;

        // ----- PATRIMOINE -----
        case 'save_patrimoine':
            requireAuth();
            if (!empty($input)) {
                savePatrimoine($pdo, $input);
                echo json_encode(['success' => true, 'message' => 'Bien de patrimoine enregistré']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Données patrimoine invalides']);
            }
            break;

        case 'delete_patrimoine':
            requireAuth();
            $id = $_GET['id'] ?? null;
            if ($id) {
                deleteGenericItem($pdo, 'patrimoine', $id);
                echo json_encode(['success' => true, 'message' => 'Bien supprimé du patrimoine']);
            } else {
                echo json_encode(['success' => false, 'message' => 'ID manquant']);
            }
            break;

        // ----- RELEVÉS -----
        case 'save_releve':
            requireAuth();
            if (!empty($input['patrimoine_id']) && isset($input['valeur'])) {
                saveReleve($pdo, $input);
                echo json_encode(['success' => true, 'message' => 'Relevé enregistré']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Relevé incomplet']);
            }
            break;

        case 'delete_releve':
            requireAuth();
            $id = $_GET['id'] ?? null;
            if ($id) {
                deleteGenericItem($pdo, 'releves_consommation', $id);
                echo json_encode(['success' => true, 'message' => 'Relevé supprimé']);
            } else {
                echo json_encode(['success' => false, 'message' => 'ID manquant']);
            }
            break;

        // ----- UTILISATEURS (admin uniquement) -----
        case 'save_user':
            requireRole(['admin']);
            if (!empty($input)) {
                saveUser($pdo, $input);
                echo json_encode(['success' => true, 'message' => 'Utilisateur enregistré']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Données utilisateur invalides']);
            }
            break;

        case 'delete_user':
            requireRole(['admin']);
            $id = $_GET['id'] ?? null;
            if ($id) {
                deleteGenericItem($pdo, 'utilisateurs', $id);
                echo json_encode(['success' => true, 'message' => 'Utilisateur supprimé']);
            } else {
                echo json_encode(['success' => false, 'message' => 'ID manquant']);
            }
            break;

        // ----- GÉNÉRIQUE (communes, catégories, équipements...) -----
        case 'save_item':
            requireAuth();
            $table = $_GET['table'] ?? '';
            if (isValidTable($table) && !empty($input)) {
                saveGenericItem($pdo, $table, $input, $GLOBALS['ALLOWED_COLUMNS']);
                echo json_encode(['success' => true, 'message' => "Élément enregistré dans $table"]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Table non autorisée ou données manquantes']);
            }
            break;

        case 'delete_item':
            requireAuth();
            $table = $_GET['table'] ?? '';
            $id = $_GET['id'] ?? null;
            if (isValidTable($table) && $id) {
                deleteGenericItem($pdo, $table, $id);
                echo json_encode(['success' => true, 'message' => "Élément $id supprimé de $table"]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Suppression impossible']);
            }
            break;

        default:
            echo json_encode(['success' => true, 'status' => 'API Cit-Gest v4.3 opérationnelle']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    // En prod, ne jamais renvoyer $e->getMessage() tel quel au client (fuite d'infos internes)
    error_log('Cit-Gest API error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erreur serveur']);
}

// ============================================================
// AUTH HELPERS
// ============================================================
function currentUser() {
    return $_SESSION['user'] ?? null;
}

function requireAuth() {
    if (!currentUser()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentification requise']);
        exit;
    }
}

function requireRole(array $roles) {
    requireAuth();
    $u = currentUser();
    if (!in_array($u['role'], $roles, true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Accès refusé']);
        exit;
    }
}

// ============================================================
// DATABASE SETUP
// ============================================================
function initDatabase($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS communes (
        id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE, respTech TEXT, phone TEXT
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE, emoji TEXT
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS patrimoine (
        id INTEGER PRIMARY KEY AUTOINCREMENT, commune TEXT, name TEXT, type_bien TEXT,
        num_inventaire TEXT, address TEXT, ref_cadastrale TEXT, statut_propriete TEXT,
        valeur_acquisition REAL, date_achat TEXT, subventions TEXT, assurance_ref TEXT,
        assurance_montant REAL, surface_sol REAL, surface_utile REAL, nb_pieces INTEGER,
        num_serie TEXT, etat_general TEXT, diagnostics TEXT, elecMeter TEXT, waterMeter TEXT,
        gasMeter TEXT, controles_reglementaires TEXT, contrats_entretien TEXT, notes TEXT, documents TEXT
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS releves_consommation (
        id INTEGER PRIMARY KEY AUTOINCREMENT, patrimoine_id INTEGER, type TEXT, valeur REAL,
        dateReleve TEXT, agentName TEXT, remarque TEXT
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS utilisateurs (
        id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE, password TEXT, name TEXT,
        email TEXT, role TEXT, commune TEXT, skill TEXT, status TEXT, avatar TEXT
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS equipements (
        id TEXT PRIMARY KEY, name TEXT, owner TEXT, status TEXT, desc TEXT, icon TEXT
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tickets (
        id TEXT PRIMARY KEY, commune TEXT, category TEXT, title TEXT, location TEXT,
        priority TEXT, status TEXT, requester TEXT, contact TEXT, description TEXT,
        dateCreated TEXT, assignedAgentId INTEGER
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS commentaires (
        id INTEGER PRIMARY KEY AUTOINCREMENT, ticket_id TEXT, author TEXT, text TEXT, dateCreated TEXT
    )");

    // Seed uniquement si base vide (mots de passe HACHÉS dès la création)
    $stmt = $pdo->query("SELECT COUNT(*) FROM communes");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO communes (name, respTech, phone) VALUES
            ('Gimont', 'Jean Dupont', '05 62 67 70 02'),
            ('Aubiet', 'Marc Bernard', '05 62 65 90 01'),
            ('Juilles', 'Alain Mercier', '05 62 65 91 10')");

        $pdo->exec("INSERT INTO categories (name, emoji) VALUES
            ('Voirie & Signalisat.', '🛣️'),
            ('Bâtiments & Écoles', '🏫'),
            ('Espaces Verts', '🌳'),
            ('Éclairage / Élec', '💡'),
            ('Festivités & Matériel', '🎪'),
            ('Propreté & Salubrité', '🧹')");

        $seedUsers = [
            ['admin', 'admin', 'Administrateur Général', 'admin@citgest.fr', 'admin', 'Gimont', 'Admin Système & DGS', '⚡'],
            ['j.dupont', 'demo123', 'Jean Dupont', 'j.dupont@gimont.fr', 'responsable', 'Gimont', 'Responsable ST & Plomberie', '👨‍💼'],
            ['p.martin', 'demo123', 'Paul Martin', 'p.martin@gimont.fr', 'agent', 'Gimont', 'Plomberie / CACES Nacelle', '👨‍🔧'],
            ['m.curie', 'demo123', 'Marie Curie', 'm.curie@aubiet.fr', 'direction', 'Aubiet', 'Maire / Direction', '🏛️'],
        ];
        $stmtU = $pdo->prepare("INSERT INTO utilisateurs (username, password, name, email, role, commune, skill, status, avatar) VALUES (?, ?, ?, ?, ?, ?, ?, 'Disponible', ?)");
        foreach ($seedUsers as $u) {
            $u[1] = password_hash($u[1], PASSWORD_DEFAULT); // hash du mot de passe de démo
            $stmtU->execute($u);
        }
        // ⚠️ Pense à changer ces mots de passe de démo avant toute mise en production réelle.

        $pdo->exec("INSERT INTO equipements (id, name, owner, status, desc, icon) VALUES
            ('EQ-1', 'Nacelle Élévatrice 18M', 'Gimont', 'Libre', 'Élagage et éclairage public.', '🏗️'),
            ('EQ-2', 'Broyeur de branches (80 HP)', 'Aubiet', 'En utilisation', 'Broyeur de branches tracté.', '🪵')");
    }
}

// ============================================================
// DATA HELPERS
// ============================================================
function isValidTable($table) {
    global $ALLOWED_COLUMNS;
    return array_key_exists($table, $ALLOWED_COLUMNS);
}

function getTableData($pdo, $table, $stripPassword = false) {
    $rows = $pdo->query("SELECT * FROM {$table}")->fetchAll();
    if ($stripPassword) {
        foreach ($rows as &$r) { unset($r['password']); }
    }
    return $rows;
}

function getTicketsWithComments($pdo) {
    $tickets = $pdo->query("SELECT * FROM tickets ORDER BY rowid DESC")->fetchAll();
    foreach ($tickets as &$t) {
        $stmt = $pdo->prepare("SELECT author, text, dateCreated as date FROM commentaires WHERE ticket_id = ? ORDER BY id ASC");
        $stmt->execute([$t['id']]);
        $t['comments'] = $stmt->fetchAll();
    }
    return $tickets;
}

function saveTicket($pdo, $t) {
    $stmt = $pdo->prepare("INSERT OR REPLACE INTO tickets (id, commune, category, title, location, priority, status, requester, contact, description, dateCreated, assignedAgentId) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $t['id'], $t['commune'], $t['category'], $t['title'], $t['location'],
        $t['priority'], $t['status'], $t['requester'], $t['contact'],
        $t['description'], $t['dateCreated'] ?? date('Y-m-d H:i'), $t['assignedAgentId'] ?? null
    ]);
}

// Création publique : INSERT strict, ne permet jamais d'écraser un ticket existant
function insertTicketOnly($pdo, $t) {
    $stmt = $pdo->prepare("INSERT INTO tickets (id, commune, category, title, location, priority, status, requester, contact, description, dateCreated, assignedAgentId) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $t['id'], $t['commune'] ?? '', $t['category'] ?? '', $t['title'] ?? '', $t['location'] ?? '',
        $t['priority'] ?? 'MOYEN', $t['status'], $t['requester'] ?? '', $t['contact'] ?? '',
        $t['description'] ?? '', date('Y-m-d H:i'), $t['assignedAgentId']
    ]);
}

function savePatrimoine($pdo, $p) {
    $cols = ['commune','name','type_bien','num_inventaire','address','ref_cadastrale','statut_propriete',
             'valeur_acquisition','date_achat','subventions','assurance_ref','assurance_montant',
             'surface_sol','surface_utile','nb_pieces','num_serie','etat_general','diagnostics',
             'elecMeter','waterMeter','gasMeter','controles_reglementaires','contrats_entretien','notes','documents'];
    $vals = array_map(fn($c) => $p[$c] ?? (is_numeric($p[$c] ?? null) ? 0 : ''), $cols);

    if (!empty($p['id'])) {
        $set = implode('=?, ', $cols) . '=?';
        $stmt = $pdo->prepare("UPDATE patrimoine SET $set WHERE id=?");
        $stmt->execute([...$vals, $p['id']]);
    } else {
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $stmt = $pdo->prepare("INSERT INTO patrimoine (" . implode(',', $cols) . ") VALUES ($placeholders)");
        $stmt->execute($vals);
    }
}

function saveUser($pdo, $u) {
    $username = $u['username'] ?? strtolower(explode(' ', $u['name'])[0]);
    // Si un mot de passe est fourni, on le hache. Sinon (édition sans changement), on ne le touche pas.
    if (!empty($u['id'])) {
        if (!empty($u['password'])) {
            $stmt = $pdo->prepare("UPDATE utilisateurs SET username=?, password=?, name=?, email=?, role=?, commune=?, skill=?, status=?, avatar=? WHERE id=?");
            $stmt->execute([$username, password_hash($u['password'], PASSWORD_DEFAULT), $u['name'], $u['email'], $u['role'], $u['commune'], $u['skill'] ?? '', $u['status'] ?? 'Disponible', $u['avatar'] ?? '👤', $u['id']]);
        } else {
            $stmt = $pdo->prepare("UPDATE utilisateurs SET username=?, name=?, email=?, role=?, commune=?, skill=?, status=?, avatar=? WHERE id=?");
            $stmt->execute([$username, $u['name'], $u['email'], $u['role'], $u['commune'], $u['skill'] ?? '', $u['status'] ?? 'Disponible', $u['avatar'] ?? '👤', $u['id']]);
        }
    } else {
        $pass = password_hash($u['password'] ?? bin2hex(random_bytes(6)), PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO utilisateurs (username, password, name, email, role, commune, skill, status, avatar) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$username, $pass, $u['name'], $u['email'], $u['role'], $u['commune'], $u['skill'] ?? '', $u['status'] ?? 'Disponible', $u['avatar'] ?? '👤']);
    }
}

function addComment($pdo, $ticketId, $author, $text) {
    $stmt = $pdo->prepare("INSERT INTO commentaires (ticket_id, author, text, dateCreated) VALUES (?, ?, ?, ?)");
    $stmt->execute([$ticketId, $author, $text, date('Y-m-d H:i')]);
}

function saveReleve($pdo, $r) {
    $stmt = $pdo->prepare("INSERT INTO releves_consommation (patrimoine_id, type, valeur, dateReleve, agentName, remarque) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$r['patrimoine_id'], $r['type'], $r['valeur'], $r['dateReleve'] ?? date('Y-m-d'), $r['agentName'] ?? currentUser()['name'] ?? 'Agent ST', $r['remarque'] ?? '']);
}

// Filtre les colonnes contre la whitelist avant de construire le SQL
function saveGenericItem($pdo, $table, $item, $allowedColumns) {
    $allowed = $allowedColumns[$table] ?? [];
    $filtered = array_intersect_key($item, array_flip($allowed));
    if (empty($filtered)) {
        throw new Exception('Aucune colonne valide fournie');
    }
    $columns = array_keys($filtered);
    $placeholders = array_fill(0, count($columns), '?');
    $sql = "INSERT OR REPLACE INTO {$table} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($filtered));
}

function deleteGenericItem($pdo, $table, $id) {
    $stmt = $pdo->prepare("DELETE FROM {$table} WHERE id = ?");
    $stmt->execute([$id]);
}
