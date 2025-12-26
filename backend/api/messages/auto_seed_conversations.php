<?php
session_start();
header('Content-Type: application/json');
require_once '../../config/database.php';

$db = new Database();
$pdo = $db->getConnection();

/**
 * Create or fetch an existing private (1:1) conversation between two users.
 * Titles will dynamically show both participants' names.
 */
function ensurePrivateConversation(PDO $pdo, int $user1, int $user2, int $companyId) {
    // Always keep smaller user_id first to avoid duplicates
    if ($user1 > $user2) {
        [$user1, $user2] = [$user2, $user1];
    }

    // Check if a private chat already exists between these users
    $check = $pdo->prepare("
        SELECT c.conversation_id
        FROM conversations c
        JOIN conversation_participants cp1 ON c.conversation_id = cp1.conversation_id
        JOIN conversation_participants cp2 ON c.conversation_id = cp2.conversation_id
        WHERE c.is_group = 0
          AND c.company_id = ?
          AND cp1.user_id = ?
          AND cp2.user_id = ?
        LIMIT 1
    ");
    $check->execute([$companyId, $user1, $user2]);
    $existing = $check->fetchColumn();
    if ($existing) return $existing;

    // Fetch display names
    $nameQuery = $pdo->prepare("SELECT CONCAT(first_name, ' ', last_name) FROM users WHERE user_id = ?");
    $nameQuery->execute([$user1]);
    $name1 = $nameQuery->fetchColumn();

    $nameQuery->execute([$user2]);
    $name2 = $nameQuery->fetchColumn();

    $title = trim("$name1 & $name2");

    // Create conversation
    $insert = $pdo->prepare("
        INSERT INTO conversations (title, is_group, company_id, created_at, updated_at)
        VALUES (?, 0, ?, NOW(), NOW())
    ");
    $insert->execute([$title, $companyId]);
    $conversationId = $pdo->lastInsertId();

    // Add participants
    $add = $pdo->prepare("INSERT INTO conversation_participants (conversation_id, user_id) VALUES (?, ?)");
    $add->execute([$conversationId, $user1]);
    $add->execute([$conversationId, $user2]);

    return $conversationId;
}

/**
 * Define who can chat with whom by role.
 */
function canChat(string $role1, string $role2): bool {
    $rules = [
        'Admin'    => ['Admin', 'Manager'],
        'Manager'  => ['Manager', 'Employee', 'Client'],
        'Employee' => ['Manager', 'Employee'],
        'Client'   => ['Manager'],
    ];
    return in_array($role2, $rules[$role1] ?? []);
}

/**
 * Run through all users and seed allowed conversations.
 */
try {
    $users = $pdo->query("SELECT user_id, role, company_id FROM users")->fetchAll(PDO::FETCH_ASSOC);
    $created = 0;

    foreach ($users as $u1) {
        foreach ($users as $u2) {
            if ($u1['user_id'] === $u2['user_id']) continue; // Skip self
            if ($u1['company_id'] !== $u2['company_id']) continue; // Different companies
            if (!canChat($u1['role'], $u2['role'])) continue;

            ensurePrivateConversation($pdo, $u1['user_id'], $u2['user_id'], $u1['company_id']);
            $created++;
        }
    }

    echo json_encode([
        "status" => "success",
        "message" => "Auto-seeding complete",
        "pairs_created" => $created
    ]);
} catch (PDOException $e) {
    echo json_encode([
        "status" => "error",
        "error" => $e->getMessage()
    ]);
}
