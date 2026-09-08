<?php
/**
 * Cit-Gest - API Backend PHP & Base SQLite v4.0
 * Gestion des interventions, lieux, compteurs de consommation et utilisateurs unifiés.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$dbFile = __DIR__ . '/database.sqlite';

try {
    $pdo = new PDO("sqlite:" . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    initDatabase($pdo);

    $action = $_GET['action'] ?? 'get_all';
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    switch ($action) {
        case 'get_all':
            echo json_encode([
                'success' => true,
                'tickets' => getTicketsWithComments($pdo),
                'categories' => getTableData($pdo, 'categories'),
                'locations' => getTableData($pdo, 'lieux'),
                'communes' => getTableData($pdo, 'communes'),
                'users' => getTableData($pdo, 'utilisateurs'),
                'equipment' => getTableData($pdo, 'equipements'),
                'releves' => getTableData($pdo, 'releves_consommation')
            ]);
            break;

        case 'save_ticket':
            if (!empty($input)) {
                saveTicket($pdo, $input);
                echo json_encode(['success' => true, 'message' => 'Ticket enregistré']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Données invalides']);
            }
            break;

        case 'add_comment':
            if (!empty($input['ticket_id']) && !empty($input['text'])) {
                addComment($pdo, $input['ticket_id'], $input['author'] ?? 'Anonyme', $input['text']);
                echo json_encode(['success' => true, 'message' => 'Commentaire ajouté']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Commentaire incomplet']);
            }
            break;

        case 'save_releve':
            if (!empty($input['lieu_id']) && isset($input['valeur'])) {
                saveReleve($pdo, $input);
                echo json_encode(['success' => true, 'message' => 'Relevé enregistré']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Relevé incomplet']);
            }
            break;

        case 'delete_releve':
            $id = $_GET['id'] ?? null;
            if ($id) {
                deleteGenericItem($pdo, 'releves_consommation', $id);
                echo json_encode(['success' => true, 'message' => 'Relevé supprimé']);
            } else {
                echo json_encode(['success' => false, 'message' => 'ID manquant']);
            }
            break;

        case 'save_item':
            $table = $_GET['table'] ?? '';
            if (isValidTable($table) && !empty($input)) {
                saveGenericItem($pdo, $table, $input);
                echo json_encode(['success' => true, 'message' => "Élément enregistré dans $table"]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Table non autorisée ou données manquantes']);
            }
            break;

        case 'delete_item':
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
            echo json_encode(['success' => true, 'status' => 'API Cit-Gest v4.0 opérationnelle']);
            break;
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * STREAMING_CHUNK:Database tables initialization and default data seeding...
 */
function initDatabase($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS communes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT UNIQUE,
        respTech TEXT,
        phone TEXT
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT UNIQUE,
        emoji TEXT
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS lieux (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        commune TEXT,
        name TEXT,
        address TEXT,
        category TEXT,
        elecMeter TEXT,
        waterMeter TEXT,
        notes TEXT
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS releves_consommation (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        lieu_id INTEGER,
        type TEXT,
        valeur REAL,
        dateReleve TEXT,
        agentName TEXT
    )");

    // Structure utilisateurs unifiée (Agents inclus avec role='agent')
    $pdo->exec("CREATE TABLE IF NOT EXISTS utilisateurs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT,
        email TEXT UNIQUE,
        role TEXT,
        commune TEXT,
        skill TEXT,
        status TEXT,
        avatar TEXT
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS equipements (
        id TEXT PRIMARY KEY,
        name TEXT,
        owner TEXT,
        status TEXT,
        desc TEXT,
        icon TEXT
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tickets (
        id TEXT PRIMARY KEY,
        commune TEXT,
        category TEXT,
        title TEXT,
        location TEXT,
        priority TEXT,
        status TEXT,
        requester TEXT,
        contact TEXT,
        description TEXT,
        dateCreated TEXT,
        assignedAgentId INTEGER
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS commentaires (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ticket_id TEXT,
        author TEXT,
        text TEXT,
        dateCreated TEXT
    )");

    // Données initiales
    $stmt = $pdo->query("SELECT COUNT(*) FROM communes");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO communes (name, respTech, phone) VALUES 
            ('Saint-Aurin', 'Jean Dupont', '05 62 00 11 22'), 
            ('Val-de-Marse', 'Marc Bernard', '05 62 11 22 33'), 
            ('Beaulieu-les-Pins', 'Alain Mercier', '05 62 22 33 44')");

        $pdo->exec("INSERT INTO categories (name, emoji) VALUES 
            ('Voirie & Signalisat.', '🛣️'), 
            ('Bâtiments & Écoles', '🏫'), 
            ('Espaces Verts', '🌳'), 
            ('Éclairage / Élec', '💡'), 
            ('Festivités & Matériel', '🎪'), 
            ('Propreté & Salubrité', '🧹')");

        $pdo->exec("INSERT INTO lieux (commune, name, address, category, elecMeter, waterMeter, notes) VALUES 
            ('Saint-Aurin', 'École Maternelle Les Lutins', '12 Rue des Écoles', 'Bâtiment', 'ELE-99823', 'EAU-1029', 'Accès par le portillon sud'),
            ('Saint-Aurin', 'Mairie Centrale & Place', '1 Place de la République', 'Administration', 'ELE-10029', 'EAU-3301', 'Compteur eau dans la cave'),
            ('Saint-Aurin', 'Gymnase Municipal', 'Avenue du Sport', 'Sport', 'ELE-44910', 'EAU-8821', 'Nécessite clé passe P1'),
            ('Val-de-Marse', 'Place de la Halle', 'Place de la Halle', 'Espace Public', 'ELE-77210', '', 'Coffret électrique festivités'),
            ('Beaulieu-les-Pins', 'Salle des Fêtes Communale', 'Route de la Forêt', 'Festivités', 'ELE-55201', 'EAU-4402', 'Disjoncteur général dans le sas')");

        // Utilisateurs unifiés : Agents + Responsable + Direction + Admin
        $pdo->exec("INSERT INTO utilisateurs (name, email, role, commune, skill, status, avatar) VALUES 
            ('Jean Dupont', 'j.dupont@st-aurin.fr', 'responsable', 'Saint-Aurin', 'Gestion & Chef Plomberie', 'Disponible', '👨‍💼'),
            ('Paul Martin', 'p.martin@st-aurin.fr', 'agent', 'Saint-Aurin', 'Plomberie / CACES Nacelle', 'Disponible', '👨‍🔧'),
            ('Marc Lambert', 'm.lambert@st-aurin.fr', 'agent', 'Saint-Aurin', 'Électricien / Habilitation BR', 'En mission', '⚡'),
            ('Pierre Moreau', 'p.moreau@st-aurin.fr', 'agent', 'Saint-Aurin', 'Espaces Verts / Élagage', 'Disponible', '🌳'),
            ('Marie Curie', 'm.curie@val-demarse.fr', 'direction', 'Val-de-Marse', 'Directrice Générale', 'Disponible', '🏛️'),
            ('Administrateur', 'admin@citgest.fr', 'admin', 'Saint-Aurin', 'Gestion Système', 'Disponible', '⚡')");

        $pdo->exec("INSERT INTO equipements (id, name, owner, status, desc, icon) VALUES 
            ('EQ-1', 'Nacelle Élévatrice 18M', 'Saint-Aurin', 'Libre', 'Élagage et éclairage public.', '🏗️'),
            ('EQ-2', 'Broyeur de branches (80 HP)', 'Val-de-Marse', 'En utilisation', 'Broyeur de branches tracté.', '🪵')");

        $pdo->exec("INSERT INTO tickets (id, commune, category, title, location, priority, status, requester, contact, description, dateCreated, assignedAgentId) VALUES 
            ('INT-101', 'Saint-Aurin', 'Bâtiments & Écoles', 'Fuite d''eau lavabo école', 'École Maternelle Les Lutins', 'URGENT', 'EN_COURS', 'Mme Dubois', '06 12 34 56 78', 'Robinet fuit abondamment dans le bloc sanitaire.', '2026-09-08 08:30', 2)");

        $pdo->exec("INSERT INTO commentaires (ticket_id, author, text, dateCreated) VALUES 
            ('INT-101', 'Mme Dubois', 'Signalé à l''ouverture de l''école.', '08:30')");

        // Relevés initiaux
        $pdo->exec("INSERT INTO releves_consommation (lieu_id, type, valeur, dateReleve, agentName) VALUES 
            (1, 'Eau', 1240.5, '2026-08-01', 'Paul Martin'),
            (1, 'Eau', 1285.2, '2026-09-01', 'Paul Martin'),
            (1, 'Électricité', 34100, '2026-08-01', 'Marc Lambert'),
            (1, 'Électricité', 34820, '2026-09-01', 'Marc Lambert')");
    }
}

function isValidTable($table) {
    return in_array($table, ['communes', 'categories', 'lieux', 'utilisateurs', 'equipements', 'tickets', 'releves_consommation']);
}

function getTableData($pdo, $table) {
    return $pdo->query("SELECT * FROM {$table}")->fetchAll();
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

function addComment($pdo, $ticketId, $author, $text) {
    $stmt = $pdo->prepare("INSERT INTO commentaires (ticket_id, author, text, dateCreated) VALUES (?, ?, ?, ?)");
    $stmt->execute([$ticketId, $author, $text, date('H:i')]);
}

function saveReleve($pdo, $r) {
    $stmt = $pdo->prepare("INSERT INTO releves_consommation (lieu_id, type, valeur, dateReleve, agentName) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        $r['lieu_id'], $r['type'], $r['valeur'], $r['dateReleve'] ?? date('Y-m-d'), $r['agentName'] ?? 'Agent ST'
    ]);
}

function saveGenericItem($pdo, $table, $item) {
    $columns = array_keys($item);
    $placeholders = array_fill(0, count($columns), '?');
    
    $sql = "INSERT OR REPLACE INTO {$table} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($item));
}

function deleteGenericItem($pdo, $table, $id) {
    $stmt = $pdo->prepare("DELETE FROM {$table} WHERE id = ?");
    $stmt->execute([$id]);
}