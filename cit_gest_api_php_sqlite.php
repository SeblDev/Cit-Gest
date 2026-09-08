<?php
/**
 * Cit-Gest - Backend API PHP & Base de Données SQLite
 * Dépôt GitHub : https://github.com/SeblDev/Cit-Gest
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

    // Initialisation automatique de la base de données
    initDatabase($pdo);

    $action = $_GET['action'] ?? 'get_all';

    switch ($action) {
        case 'get_all':
            echo json_encode([
                'success' => true,
                'tickets' => getTickets($pdo),
                'categories' => getTableData($pdo, 'categories'),
                'locations' => getTableData($pdo, 'lieux'),
                'communes' => getTableData($pdo, 'communes'),
                'agents' => getTableData($pdo, 'agents'),
                'equipment' => getTableData($pdo, 'equipements')
            ]);
            break;

        case 'save_ticket':
            $data = json_decode(file_get_contents('php://input'), true);
            if ($data) {
                saveTicket($pdo, $data);
                echo json_encode(['success' => true, 'message' => 'Ticket enregistré']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Données invalides']);
            }
            break;

        default:
            echo json_encode(['success' => true, 'status' => 'API Cit-Gest opérationnelle']);
            break;
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function initDatabase($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS communes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT UNIQUE,
        population INTEGER
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT UNIQUE,
        emoji TEXT
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS lieux (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        commune TEXT,
        name TEXT
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agents (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT,
        role TEXT,
        skill TEXT,
        commune TEXT,
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

    // Insertion des données par défaut si vide
    $stmt = $pdo->query("SELECT COUNT(*) FROM communes");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO communes (name, population) VALUES ('Saint-Aurin', 3120), ('Val-de-Marse', 1850), ('Beaulieu-les-Pins', 2410)");
        $pdo->exec("INSERT INTO categories (name, emoji) VALUES ('Voirie & Signalisat.', '🛣️'), ('Bâtiments & Écoles', '🏫'), ('Espaces Verts', '🌳'), ('Éclairage / Élec', '💡'), ('Festivités & Matériel', '🎪'), ('Propreté & Salubrité', '🧹')");
        $pdo->exec("INSERT INTO lieux (commune, name) VALUES ('Saint-Aurin', 'École Maternelle Les Lutins'), ('Saint-Aurin', 'Mairie Centrale'), ('Saint-Aurin', 'Gymnase Municipal')");
        $pdo->exec("INSERT INTO agents (name, role, skill, commune, status, avatar) VALUES ('Jean Dupont', 'Chef Plomberie', 'CACES Nacelle', 'Saint-Aurin', 'Disponible', '👨‍🔧'), ('Marc Lambert', 'Électricien', 'Habilitation BR/HO', 'Saint-Aurin', 'En mission', '⚡')");
        $pdo->exec("INSERT INTO equipements (id, name, owner, status, desc, icon) VALUES ('EQ-1', 'Nacelle Élévatrice 18M', 'Saint-Aurin', 'Libre', 'Élagage et éclairage public.', '🏗️'), ('EQ-2', 'Broyeur de branches (80 HP)', 'Val-de-Marse', 'En utilisation', 'Broyeur de branches tracté.', '🪵')");
    }
}

function getTableData($pdo, $table) {
    return $pdo->query("SELECT * FROM {$table}")->fetchAll();
}

function getTickets($pdo) {
    $tickets = $pdo->query("SELECT * FROM tickets ORDER BY rowid DESC")->fetchAll();
    foreach ($tickets as &$t) {
        $t['comments'] = [];
    }
    return $tickets;
}

function saveTicket($pdo, $t) {
    $stmt = $pdo->prepare("INSERT OR REPLACE INTO tickets (id, commune, category, title, location, priority, status, requester, contact, description, dateCreated, assignedAgentId) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $t['id'], $t['commune'], $t['category'], $t['title'], $t['location'],
        $t['priority'], $t['status'], $t['requester'], $t['contact'],
        $t['description'], $t['dateCreated'], $t['assignedAgentId']
    ]);
}