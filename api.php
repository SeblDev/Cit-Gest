<?php
/**
 * Cit-Gest - API Backend PHP & Base SQLite v4.2
 * STREAMING_CHUNK:Initializing PHP headers and SQLite database connection...
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
                'patrimoine' => getTableData($pdo, 'patrimoine'),
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

        case 'save_patrimoine':
            if (!empty($input)) {
                savePatrimoine($pdo, $input);
                echo json_encode(['success' => true, 'message' => 'Bien de patrimoine enregistré']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Données patrimoine invalides']);
            }
            break;

        case 'delete_patrimoine':
            $id = $_GET['id'] ?? null;
            if ($id) {
                deleteGenericItem($pdo, 'patrimoine', $id);
                echo json_encode(['success' => true, 'message' => 'Bien supprimé du patrimoine']);
            } else {
                echo json_encode(['success' => false, 'message' => 'ID manquant']);
            }
            break;

        case 'save_releve':
            if (!empty($input['patrimoine_id']) && isset($input['valeur'])) {
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

        case 'save_user':
            if (!empty($input)) {
                saveUser($pdo, $input);
                echo json_encode(['success' => true, 'message' => 'Utilisateur enregistré']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Données utilisateur invalides']);
            }
            break;

        case 'delete_user':
            $id = $_GET['id'] ?? null;
            if ($id) {
                deleteGenericItem($pdo, 'utilisateurs', $id);
                echo json_encode(['success' => true, 'message' => 'Utilisateur supprimé']);
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
            echo json_encode(['success' => true, 'status' => 'API Cit-Gest v4.2 opérationnelle']);
            break;
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * STREAMING_CHUNK:Defining database tables creation helper function...
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

    $pdo->exec("CREATE TABLE IF NOT EXISTS patrimoine (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        commune TEXT,
        name TEXT,
        type_bien TEXT,
        num_inventaire TEXT,
        address TEXT,
        ref_cadastrale TEXT,
        statut_propriete TEXT,
        valeur_acquisition REAL,
        date_achat TEXT,
        subventions TEXT,
        assurance_ref TEXT,
        assurance_montant REAL,
        surface_sol REAL,
        surface_utile REAL,
        nb_pieces INTEGER,
        num_serie TEXT,
        etat_general TEXT,
        diagnostics TEXT,
        elecMeter TEXT,
        waterMeter TEXT,
        gasMeter TEXT,
        controles_reglementaires TEXT,
        contrats_entretien TEXT,
        notes TEXT,
        documents TEXT
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS releves_consommation (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        patrimoine_id INTEGER,
        type TEXT,
        valeur REAL,
        dateReleve TEXT,
        agentName TEXT,
        remarque TEXT
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS utilisateurs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE,
        password TEXT,
        name TEXT,
        email TEXT,
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

    // Seeding initial context
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

        $pdo->exec("INSERT INTO patrimoine (commune, name, type_bien, num_inventaire, address, ref_cadastrale, statut_propriete, valeur_acquisition, date_achat, subventions, assurance_ref, assurance_montant, surface_sol, surface_utile, nb_pieces, num_serie, etat_general, diagnostics, elecMeter, waterMeter, gasMeter, controles_reglementaires, contrats_entretien, notes, documents) VALUES 
            ('Gimont', 'École Maternelle Saint-Éloi', 'Bâtiment', 'PAT-2024-001', 'Rue des Écoles', 'AC-104', 'Domaine public communal', 1250000, '2005-06-15', 'DETR 2005 (30%)', 'POL-99218-AXA', 1800, 850, 620, 8, '', 'Bon état général', 'DPE: C | Amiante: Réalisé 2021 | Plomb: Conforme', 'ELE-99823', 'EAU-1029', 'GAZ-4410', 'Incendie ERP: Valide 2026 | PMR: Conforme', 'Dalkia (Chauffage) - Fin 2027', 'Accès par le portillon sud. Badge Vigik N°2', 'Plan_Maternelle.pdf, Attestation_Assurance_2026.pdf'),
            ('Gimont', 'Halle Centrale & Mairie', 'Bâtiment', 'PAT-2024-002', 'Place de la Halle', 'AB-012', 'Domaine public communal', 3400000, '1990-01-01', 'Région Occitanie / Département', 'POL-99218-AXA', 3200, 1400, 1100, 14, '', 'Excellent - Rénové 2022', 'DPE: B | Amiante: Néant | Plomb: Néant', 'ELE-10029', 'EAU-3301', '', 'Incendie: Contrôle annuel Juin | Extincteurs OK', 'Apave - SSI & Électricité', 'Compteur eau situé dans la cave sous la halle', 'Notice_Securite_Halle.pdf')");

        $pdo->exec("INSERT INTO utilisateurs (username, password, name, email, role, commune, skill, status, avatar) VALUES 
            ('admin', 'admin', 'Administrateur Général', 'admin@citgest.fr', 'admin', 'Gimont', 'Admin Système & DGS', 'Disponible', '⚡'),
            ('j.dupont', 'demo123', 'Jean Dupont', 'j.dupont@gimont.fr', 'responsable', 'Gimont', 'Responsable ST & Plomberie', 'Disponible', '👨‍💼'),
            ('p.martin', 'demo123', 'Paul Martin', 'p.martin@gimont.fr', 'agent', 'Gimont', 'Plomberie / CACES Nacelle', 'Disponible', '👨‍🔧'),
            ('m.curie', 'demo123', 'Marie Curie', 'm.curie@aubiet.fr', 'direction', 'Aubiet', 'Maire / Direction', 'Disponible', '🏛️')");

        $pdo->exec("INSERT INTO equipements (id, name, owner, status, desc, icon) VALUES 
            ('EQ-1', 'Nacelle Élévatrice 18M', 'Gimont', 'Libre', 'Élagage et éclairage public.', '🏗️'),
            ('EQ-2', 'Broyeur de branches (80 HP)', 'Aubiet', 'En utilisation', 'Broyeur de branches tracté.', '🪵')");

        $pdo->exec("INSERT INTO tickets (id, commune, category, title, location, priority, status, requester, contact, description, dateCreated, assignedAgentId) VALUES 
            ('INT-101', 'Gimont', 'Bâtiments & Écoles', 'Fuite d''eau lavabo école Saint-Éloi', 'École Maternelle Saint-Éloi', 'URGENT', 'EN_COURS', 'Mme Dubois', '06 12 34 56 78', 'Robinet fuit abondamment dans le bloc sanitaire.', '2026-09-08 08:30', 3)");

        $pdo->exec("INSERT INTO releves_consommation (patrimoine_id, type, valeur, dateReleve, agentName, remarque) VALUES 
            (1, 'Eau', 1240.5, '2026-08-01', 'Paul Martin', 'Index normal'),
            (1, 'Eau', 1285.2, '2026-09-01', 'Paul Martin', 'Consommation stable'),
            (1, 'Électricité', 34100, '2026-08-01', 'Marc Lambert', 'Début période été'),
            (1, 'Électricité', 34820, '2026-09-01', 'Marc Lambert', 'Relevé mensuel standard')");
    }
}

/**
 * STREAMING_CHUNK:Defining CRUD data queries helper functions...
 */
function isValidTable($table) {
    return in_array($table, ['communes', 'categories', 'patrimoine', 'utilisateurs', 'equipements', 'tickets', 'releves_consommation']);
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

function savePatrimoine($pdo, $p) {
    if (!empty($p['id'])) {
        $stmt = $pdo->prepare("UPDATE patrimoine SET commune=?, name=?, type_bien=?, num_inventaire=?, address=?, ref_cadastrale=?, statut_propriete=?, valeur_acquisition=?, date_achat=?, subventions=?, assurance_ref=?, assurance_montant=?, surface_sol=?, surface_utile=?, nb_pieces=?, num_serie=?, etat_general=?, diagnostics=?, elecMeter=?, waterMeter=?, gasMeter=?, controles_reglementaires=?, contrats_entretien=?, notes=?, documents=? WHERE id=?");
        $stmt->execute([
            $p['commune'], $p['name'], $p['type_bien'] ?? 'Bâtiment', $p['num_inventaire'] ?? '', $p['address'] ?? '',
            $p['ref_cadastrale'] ?? '', $p['statut_propriete'] ?? '', $p['valeur_acquisition'] ?? 0, $p['date_achat'] ?? '',
            $p['subventions'] ?? '', $p['assurance_ref'] ?? '', $p['assurance_montant'] ?? 0, $p['surface_sol'] ?? 0,
            $p['surface_utile'] ?? 0, $p['nb_pieces'] ?? 0, $p['num_serie'] ?? '', $p['etat_general'] ?? '',
            $p['diagnostics'] ?? '', $p['elecMeter'] ?? '', $p['waterMeter'] ?? '', $p['gasMeter'] ?? '',
            $p['controles_reglementaires'] ?? '', $p['contrats_entretien'] ?? '', $p['notes'] ?? '', $p['documents'] ?? '', $p['id']
        ]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO patrimoine (commune, name, type_bien, num_inventaire, address, ref_cadastrale, statut_propriete, valeur_acquisition, date_achat, subventions, assurance_ref, assurance_montant, surface_sol, surface_utile, nb_pieces, num_serie, etat_general, diagnostics, elecMeter, waterMeter, gasMeter, controles_reglementaires, contrats_entretien, notes, documents) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $p['commune'], $p['name'], $p['type_bien'] ?? 'Bâtiment', $p['num_inventaire'] ?? '', $p['address'] ?? '',
            $p['ref_cadastrale'] ?? '', $p['statut_propriete'] ?? '', $p['valeur_acquisition'] ?? 0, $p['date_achat'] ?? '',
            $p['subventions'] ?? '', $p['assurance_ref'] ?? '', $p['assurance_montant'] ?? 0, $p['surface_sol'] ?? 0,
            $p['surface_utile'] ?? 0, $p['nb_pieces'] ?? 0, $p['num_serie'] ?? '', $p['etat_general'] ?? '',
            $p['diagnostics'] ?? '', $p['elecMeter'] ?? '', $p['waterMeter'] ?? '', $p['gasMeter'] ?? '',
            $p['controles_reglementaires'] ?? '', $p['contrats_entretien'] ?? '', $p['notes'] ?? '', $p['documents'] ?? ''
        ]);
    }
}

function saveUser($pdo, $u) {
    if (!empty($u['id'])) {
        $stmt = $pdo->prepare("UPDATE utilisateurs SET username=?, password=?, name=?, email=?, role=?, commune=?, skill=?, status=?, avatar=? WHERE id=?");
        $stmt->execute([
            $u['username'] ?? strtolower(explode(' ', $u['name'])[0]), $u['password'] ?? 'demo123',
            $u['name'], $u['email'], $u['role'], $u['commune'],
            $u['skill'] ?? '', $u['status'] ?? 'Disponible', $u['avatar'] ?? '👤', $u['id']
        ]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO utilisateurs (username, password, name, email, role, commune, skill, status, avatar) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $u['username'] ?? strtolower(explode(' ', $u['name'])[0]), $u['password'] ?? 'demo123',
            $u['name'], $u['email'], $u['role'], $u['commune'],
            $u['skill'] ?? '', $u['status'] ?? 'Disponible', $u['avatar'] ?? '👤'
        ]);
    }
}

function addComment($pdo, $ticketId, $author, $text) {
    $stmt = $pdo->prepare("INSERT INTO commentaires (ticket_id, author, text, dateCreated) VALUES (?, ?, ?, ?)");
    $stmt->execute([$ticketId, $author, $text, date('H:i')]);
}

function saveReleve($pdo, $r) {
    $stmt = $pdo->prepare("INSERT INTO releves_consommation (patrimoine_id, type, valeur, dateReleve, agentName, remarque) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $r['patrimoine_id'], $r['type'], $r['valeur'], $r['dateReleve'] ?? date('Y-m-d'), $r['agentName'] ?? 'Agent ST', $r['remarque'] ?? ''
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