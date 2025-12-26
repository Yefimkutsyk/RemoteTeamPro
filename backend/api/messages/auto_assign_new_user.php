<?php
session_start();
header('Content-Type: application/json');
require_once '../../config/database.php';

$db = new Database();
$pdo = $db->getConnection();

/**
 * Create or fetch a private chat between two users.
 * Uses lowercase tables: conversations, conversation_participants
 */
function ensurePrivateConversation(PDO $pdo, int $u1, int $u2, int $companyId) {
    if ($u1 > $u2) [$u1, $u2] = [$u2, $u1];

    $existing = $pdo->prepare("
        SELECT c.conversation_id
        FROM conversations c
        JOIN conversation_participants p1 ON c.conversation_id=p1.conversation_id
        JOIN conversation_participants p2 ON c.conversation_id=p2.conversation_id
        WHERE c.is_group=0 
          AND c.company_id=?
          AND p1.user_id=? 
          AND p2.user_id=?
        LIMIT 1
    ");
    $existing->execute([$companyId, $u1, $u2]);
    $cid = $existing->fetchColumn();
    if ($cid) return $cid;

    // Make title
    $name = $pdo->prepare("SELECT CONCAT(first_name,' ',last_name) FROM Users WHERE user_id=?");
    $name->execute([$u1]);
    $n1 = $name->fetchColumn() ?: "User {$u1}";
    $name->execute([$u2]);
    $n2 = $name->fetchColumn() ?: "User {$u2}";

    $title = trim("$n1 & $n2");

    // Create chat
    $insert = $pdo->prepare("INSERT INTO conversations (title,is_group,company_id,created_at,updated_at) VALUES (?,?,?,?,?)");
    $now = date('Y-m-d H:i:s');
    $insert->execute([$title,0,$companyId,$now,$now]);
    $cid = $pdo->lastInsertId();

    // Add both members (use conversation_participants table)
    $add = $pdo->prepare("INSERT INTO conversation_participants (conversation_id,user_id,joined_at) VALUES (?,?,?)");
    $add->execute([$cid,$u1,$now]);
    $add->execute([$cid,$u2,$now]);

    return $cid;
}

/**
 * Try to determine the assigned manager for a client user.
 * Priority:
 *  1) ClientRequests.manager_id (most direct)
 *  2) Projects.manager_id where client_id = user
 *  3) Teams: find a team where the user is member and return that team's manager (fallback)
 *
 * Returns manager user_id (int) or null.
 */
function getAssignedManager(PDO $pdo, int $clientUserId, int $companyId) {
    // 1) ClientRequests
    $stmt = $pdo->prepare("
        SELECT manager_id
        FROM ClientRequests
        WHERE client_id = ?
          AND company_id = ?
          AND manager_id IS NOT NULL
        LIMIT 1
    ");
    $stmt->execute([$clientUserId, $companyId]);
    $manager = $stmt->fetchColumn();
    if ($manager) return (int)$manager;

    // 2) Projects
    $stmt = $pdo->prepare("
        SELECT manager_id
        FROM Projects
        WHERE client_id = ?
          AND company_id = ?
          AND manager_id IS NOT NULL
        LIMIT 1
    ");
    $stmt->execute([$clientUserId, $companyId]);
    $manager = $stmt->fetchColumn();
    if ($manager) return (int)$manager;

    // 3) Teams (fallback): is the user a team member? return that team's manager.
    $stmt = $pdo->prepare("
        SELECT t.manager_id
        FROM Teams t
        JOIN TeamMembers tm ON tm.team_id = t.team_id
        WHERE tm.user_id = ?
          AND t.company_id = ?
        LIMIT 1
    ");
    $stmt->execute([$clientUserId, $companyId]);
    $manager = $stmt->fetchColumn();
    if ($manager) return (int)$manager;

    return null;
}

/**
 * Chat permission rules. This checks role-based permissions and uses getAssignedManager when needed.
 */
function canChat($r1, $r2, $u1, $u2, $pdo, $companyId) {
    // Admin → Admin OR Manager
    if ($r1 === "Admin") {
        return in_array($r2, ["Admin", "Manager"], true);
    }

    // Manager → Manager, Employee, Admin, OR Client assigned to them
    if ($r1 === "Manager") {
        if (in_array($r2, ["Manager", "Employee", "Admin"], true)) return true;

        if ($r2 === "Client") {
            // check if $u2's assigned manager is $u1
            $assigned = getAssignedManager($pdo, $u2, $companyId);
            return $assigned !== null && (int)$assigned === (int)$u1;
        }
        return false;
    }

    // Employee → Employee OR Manager
    if ($r1 === "Employee") {
        return in_array($r2, ["Employee", "Manager"], true);
    }

    // Client → only their assigned Manager
    if ($r1 === "Client") {
        $assigned = getAssignedManager($pdo, $u1, $companyId);
        return $assigned !== null && (int)$assigned === (int)$u2;
    }

    return false;
}

/**
 * MAIN: Assign chats for a newly created user.
 */
try {
    $newUserId = $_GET['user_id'] ?? null;
    if (!$newUserId) {
        echo json_encode(["error" => "Missing user_id"]);
        exit;
    }

    // New user's data
    $query = $pdo->prepare("SELECT user_id, role, company_id FROM Users WHERE user_id = ?");
    $query->execute([$newUserId]);
    $new = $query->fetch(PDO::FETCH_ASSOC);

    if (!$new) {
        echo json_encode(["error" => "User not found"]);
        exit;
    }

    $company = (int)$new['company_id'];
    $roleA = $new['role'];

    // Load all existing users in same company (only active users)
    $users = $pdo->prepare("SELECT user_id, role FROM Users WHERE company_id = ? AND user_id != ?");
    $users->execute([$company, $newUserId]);
    $all = $users->fetchAll(PDO::FETCH_ASSOC);

    $created = 0;
    $createdPairs = [];

    foreach ($all as $u) {
        $roleB = $u['role'];
        $otherId = (int)$u['user_id'];

        // If new user can chat with other
        if (canChat($roleA, $roleB, $newUserId, $otherId, $pdo, $company)) {
            $cid = ensurePrivateConversation($pdo, $newUserId, $otherId, $company);
            if ($cid) {
                $created++;
                $createdPairs[] = ['conversation_id' => $cid, 'a' => $newUserId, 'b' => $otherId];
            }
        }

        // If other can chat with new user (cover asymmetric rules)
        if (canChat($roleB, $roleA, $otherId, $newUserId, $pdo, $company)) {
            $cid = ensurePrivateConversation($pdo, $otherId, $newUserId, $company);
            if ($cid) {
                $created++;
                $createdPairs[] = ['conversation_id' => $cid, 'a' => $otherId, 'b' => $newUserId];
            }
        }
    }

    echo json_encode([
        "status" => "success",
        "message" => "Chats assigned successfully",
        "created_pairs_count" => $created,
        "created_pairs" => $createdPairs
    ]);
} catch (Exception $e) {
    // return safe error
    error_log("auto_assign_new_user error: " . $e->getMessage());
    echo json_encode(["error" => $e->getMessage()]);
}
?>
