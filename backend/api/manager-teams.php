<?php
session_start();
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

require_once(__DIR__ . '/../config/database.php'); // your Database class

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$inputRaw = file_get_contents("php://input");
$inputJson = json_decode($inputRaw, true) ?? [];
$action = $_GET['action'] ?? $_POST['action'] ?? $inputJson['action'] ?? null;

if (!$action) {
    echo json_encode(["success" => false, "error" => "Invalid or missing action"]);
    exit;
}

$db = new Database();
$conn = $db->getConnection();

try {
    switch ($action) {

        // ----------------------------------------------------------------
        // SYNC_TEAMS_FROM_ASSIGNMENTS
        // Create auto teams from project assignments where no team exists.
        // ----------------------------------------------------------------
        case 'SYNC_TEAMS_FROM_ASSIGNMENTS':
            $conn->beginTransaction();

            // Find projects that have at least one user assignment and no team assignment
            $sql = "
                SELECT pa.project_id
                FROM ProjectAssignments pa
                GROUP BY pa.project_id
                HAVING SUM(CASE WHEN pa.user_id IS NOT NULL THEN 1 ELSE 0 END) > 0
                   AND SUM(CASE WHEN pa.team_id IS NOT NULL THEN 1 ELSE 0 END) = 0
            ";
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $projects = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $createdCount = 0;
            foreach ($projects as $project_id) {
                // Compose team name
                $teamName = "AutoTeam - Project {$project_id}";

                // If team already exists by that name, reuse it
                $check = $conn->prepare("SELECT team_id FROM Teams WHERE team_name = ? LIMIT 1");
                $check->execute([$teamName]);
                $existing = $check->fetch(PDO::FETCH_ASSOC);
                if ($existing) {
                    $teamId = $existing['team_id'];
                } else {
                    // Use project's company_id and manager_id if available
                    $projStmt = $conn->prepare("SELECT company_id, manager_id FROM Projects WHERE project_id = ? LIMIT 1");
                    $projStmt->execute([$project_id]);
                    $proj = $projStmt->fetch(PDO::FETCH_ASSOC);

                    $company_id = $proj['company_id'] ?? null;
                    $manager_id = $proj['manager_id'] ?? null;

                    $ins = $conn->prepare("INSERT INTO Teams (company_id, team_name, manager_id, description, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())");
                    $ins->execute([$company_id, $teamName, $manager_id, "Auto-generated from assignments for project {$project_id}"]);
                    $teamId = $conn->lastInsertId();
                    $createdCount++;
                }

                // Add distinct users from ProjectAssignments to this team
                $mStmt = $conn->prepare("SELECT DISTINCT user_id FROM ProjectAssignments WHERE project_id = ? AND user_id IS NOT NULL");
                $mStmt->execute([$project_id]);
                $users = $mStmt->fetchAll(PDO::FETCH_COLUMN);
                foreach ($users as $uid) {
                    if (!$uid) continue;
                    $chk = $conn->prepare("SELECT 1 FROM TeamMembers WHERE team_id = ? AND user_id = ? LIMIT 1");
                    $chk->execute([$teamId, $uid]);
                    if (!$chk->fetchColumn()) {
                        $add = $conn->prepare("INSERT INTO TeamMembers (team_id, user_id, joined_at) VALUES (?, ?, NOW())");
                        $add->execute([$teamId, $uid]);
                    }
                }

                // Link the team to project as a team assignment (if not already)
                $paChk = $conn->prepare("SELECT 1 FROM ProjectAssignments WHERE project_id = ? AND team_id = ? LIMIT 1");
                $paChk->execute([$project_id, $teamId]);
                if (!$paChk->fetchColumn()) {
                    $link = $conn->prepare("INSERT INTO ProjectAssignments (project_id, team_id, assigned_at, role_on_project) VALUES (?, ?, NOW(), 'Auto')");
                    $link->execute([$project_id, $teamId]);
                }
            }

            $conn->commit();
            echo json_encode(["success" => true, "message" => "Sync complete", "created_teams" => $createdCount, "processed_projects" => count($projects)]);
            break;


        // ----------------------------------------------------------------
        // GET_ALL_PROJECTS
        // Returns a simple list of projects for selector, filtered by current manager
        // ----------------------------------------------------------------
        case 'GET_ALL_PROJECTS':
            $manager_id = $_SESSION['user_id'] ?? null; // Get the logged-in manager's ID
            $params = [];
            $where = "";

            if ($manager_id) {
                // Filter projects where the current user is the manager
                $where = "WHERE manager_id = ?";
                $params[] = $manager_id;
            } else {
                // Fallback: if session ID is missing, try company ID or return all (original behavior)
                $session_company = $_SESSION['company_id'] ?? null;
                if ($session_company) {
                    $where = "WHERE company_id = ?";
                    $params[] = $session_company;
                }
            }

            // Select projects
            $sql = "SELECT project_id, project_name, status FROM Projects $where ORDER BY updated_at DESC";
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);

            $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(["success" => true, "projects" => $projects]);
            break;
            
        // ----------------------------------------------------------------
        // GET_OVERALL_SUMMARY
        // Returns aggregate metrics for all projects managed by the current user.
        // ----------------------------------------------------------------
        case 'GET_OVERALL_SUMMARY':
            $manager_id = $_SESSION['user_id'] ?? null;
            if (!$manager_id) {
                echo json_encode(["success" => true, "summary" => ["total_projects" => 0, "avg_completion" => 0, "total_teams" => 0, "total_users" => 0]]);
                break;
            }
        
            // 1. Projects Count & Average Completion
            $projStmt = $conn->prepare("
                SELECT 
                    COUNT(project_id) AS total_projects,
                    AVG(completion_percentage) AS avg_completion
                FROM Projects
                WHERE manager_id = ?
            ");
            $projStmt->execute([$manager_id]);
            $projSummary = $projStmt->fetch(PDO::FETCH_ASSOC);
        
            // 2. Total Unique Teams Assigned (to manager's projects)
            $teamStmt = $conn->prepare("
                SELECT 
                    COUNT(DISTINCT pa.team_id) AS total_teams
                FROM ProjectAssignments pa
                JOIN Projects p ON pa.project_id = p.project_id
                WHERE p.manager_id = ? AND pa.team_id IS NOT NULL
            ");
            $teamStmt->execute([$manager_id]);
            $teamSummary = $teamStmt->fetch(PDO::FETCH_ASSOC);
        
            // 3. Total Unique Users Assigned (to manager's projects, individual assignments)
            $userStmt = $conn->prepare("
                SELECT 
                    COUNT(DISTINCT pa.user_id) AS total_users
                FROM ProjectAssignments pa
                JOIN Projects p ON pa.project_id = p.project_id
                WHERE p.manager_id = ? AND pa.user_id IS NOT NULL
            ");
            $userStmt->execute([$manager_id]);
            $userSummary = $userStmt->fetch(PDO::FETCH_ASSOC);
        
            $summary = [
                "total_projects" => (int)($projSummary['total_projects'] ?? 0),
                "avg_completion" => (float)($projSummary['avg_completion'] ?? 0.0),
                "total_teams" => (int)($teamSummary['total_teams'] ?? 0),
                "total_users" => (int)($userSummary['total_users'] ?? 0),
            ];
        
            echo json_encode(["success" => true, "summary" => $summary]);
            break;


        // ----------------------------------------------------------------
        // GET_PROJECT_DATA
        // Returns: project (or null), team_assignments [], user_assignments []
        // ----------------------------------------------------------------
        case 'GET_PROJECT_DATA':
            // 1. INPUT HANDLING & TYPE CASTING
            $project_id = $_GET['project_id'] ?? $inputJson['project_id'] ?? null;
            
            // If project_id is missing or cannot be cast to a valid integer ID
            if (!$project_id || !is_numeric($project_id)) {
                // Return null data gracefully
                echo json_encode([
                    "success" => true,
                    "project" => null,
                    "team_assignments" => [],
                    "user_assignments" => []
                ]);
                break;
            }
        
            // CRITICAL FIX: Cast to integer to prevent type mismatch failures in PDO/MySQL
            $project_id = (int)$project_id;
        
            // 2. FETCH PROJECT DETAILS
            $stmt = $conn->prepare("
                SELECT 
                    p.*, 
                    CONCAT(m.first_name, ' ', m.last_name) AS manager_name,
                    CONCAT(c.first_name, ' ', c.last_name) AS client_name
                FROM Projects p
                LEFT JOIN Users m ON p.manager_id = m.user_id
                LEFT JOIN Users c ON p.client_id = c.user_id
                WHERE p.project_id = ?
                LIMIT 1
            ");
            $stmt->execute([$project_id]);
            $project = $stmt->fetch(PDO::FETCH_ASSOC);
        
            if (!$project) {
                // Project missing — return success with null project
                echo json_encode([
                    "success" => true,
                    "project" => null,
                    "team_assignments" => [],
                    "user_assignments" => []
                ]);
                break;
            }
        
            // 3. FETCH TEAM ASSIGNMENTS (Optimized Query)
            $tstmt = $conn->prepare("
                SELECT 
                    pa.assignment_id, pa.team_id, t.team_name, pa.role_on_project,
                    COUNT(tm.user_id) AS member_count
                FROM ProjectAssignments pa
                JOIN Teams t ON pa.team_id = t.team_id
                LEFT JOIN TeamMembers tm ON t.team_id = tm.team_id
                WHERE pa.project_id = ? AND pa.team_id IS NOT NULL
                GROUP BY pa.assignment_id, pa.team_id, t.team_name, pa.role_on_project
            ");
            $tstmt->execute([$project_id]);
            $team_assignments = $tstmt->fetchAll(PDO::FETCH_ASSOC);
        
            // 4. FETCH USER ASSIGNMENTS (Individual Contributors)
            $ustmt = $conn->prepare("
                SELECT pa.assignment_id, pa.user_id, u.first_name, u.last_name, u.email, pa.role_on_project
                FROM ProjectAssignments pa
                JOIN Users u ON pa.user_id = u.user_id
                WHERE pa.project_id = ? AND pa.user_id IS NOT NULL
            ");
            $ustmt->execute([$project_id]);
            $user_assignments = $ustmt->fetchAll(PDO::FETCH_ASSOC);
        
            // 5. RETURN SUCCESS
            echo json_encode([
                "success" => true,
                "project" => $project,
                "team_assignments" => $team_assignments,
                "user_assignments" => $user_assignments
            ]);
            break;

        // ----------------------------------------------------------------
        // LIST_AVAILABLE_EMPLOYEES
        // Returns only rows where role = 'Employee' and status = 'Active'
        // Accepts optional company_id
        // ----------------------------------------------------------------
        case 'LIST_AVAILABLE_EMPLOYEES':
            $company_id = $_GET['company_id'] ?? $inputJson['company_id'] ?? null;

            // Build condition safely
            $conditions = [];
            $params = [];
            $conditions[] = "LOWER(u.role) = 'employee'";
            $conditions[] = "LOWER(u.status) = 'active'";
            if ($company_id) {
                $conditions[] = "u.company_id = ?";
                $params[] = $company_id;
            }

            $where = count($conditions) > 0 ? "WHERE " . implode(" AND ", $conditions) : "";

            $sql = "
                SELECT 
                    u.user_id, u.first_name, u.last_name, u.email, u.role
                FROM Users u
                $where
                ORDER BY u.last_name ASC
            ";
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(["success" => true, "employees" => $employees]);
            break;

        // ----------------------------------------------------------------
        // GET_ALL_TEAMS
        // Returns all teams with manager name and member count
        // ----------------------------------------------------------------
        case 'GET_ALL_TEAMS':
            $manager_id = $_SESSION['user_id'] ?? null;
            $params = [];
            $where = "";

            // Filter by manager (optional: could also filter by company_id)
            if ($manager_id) {
                $where = "WHERE t.manager_id = ?";
                $params[] = $manager_id;
            }

            $sql = "
                SELECT 
                    t.team_id, t.team_name, t.description, 
                    CONCAT(m.first_name, ' ', m.last_name) AS manager_name,
                    t.created_at,
                    (SELECT COUNT(*) FROM TeamMembers tm WHERE tm.team_id = t.team_id) AS member_count
                FROM Teams t
                LEFT JOIN Users m ON t.manager_id = m.user_id
                $where
                ORDER BY t.created_at DESC
            ";
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // attach projects linked to each team
            foreach ($teams as &$team) {
                $pstmt = $conn->prepare("
                    SELECT p.project_id, p.project_name
                    FROM ProjectAssignments pa
                    JOIN Projects p ON pa.project_id = p.project_id
                    WHERE pa.team_id = ?
                    GROUP BY p.project_id
                ");
                $pstmt->execute([$team['team_id']]);
                $team['projects'] = $pstmt->fetchAll(PDO::FETCH_ASSOC);
            }

            echo json_encode(["success" => true, "teams" => $teams]);
            break;

        // ----------------------------------------------------------------
        // GET_TEAM_ROSTER
        // ----------------------------------------------------------------
        case 'GET_TEAM_ROSTER':
            $team_id = $_GET['team_id'] ?? $inputJson['team_id'] ?? null;
            if (!$team_id) throw new Exception("Missing team_id");

            $stmt = $conn->prepare("
                SELECT 
                    u.user_id, u.first_name, u.last_name, u.email
                FROM TeamMembers tm
                JOIN Users u ON tm.user_id = u.user_id
                WHERE tm.team_id = ?
                ORDER BY tm.joined_at DESC
            ");
            $stmt->execute([$team_id]);
            $roster = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(["success" => true, "roster" => $roster]);
            break;

        // ----------------------------------------------------------------
        // CREATE_TEAM
        // payload: team_id (optional), team_name, description, members (array)
        // ----------------------------------------------------------------
        case 'CREATE_TEAM':
            $payload = $inputJson ?? $_POST;
            $team_id = $payload['team_id'] ?? null;
            $team_name = trim($payload['team_name'] ?? '');
            $description = $payload['description'] ?? null;
            $members = $payload['members'] ?? [];
            $manager_id = $_SESSION['user_id'] ?? null; // Team manager is current user

            if (!$team_name) throw new Exception("Team name is required");

            $conn->beginTransaction();

            if ($team_id) {
                // Update existing team
                $upd = $conn->prepare("UPDATE Teams SET team_name = ?, description = ?, updated_at = NOW() WHERE team_id = ? AND manager_id = ?");
                $upd->execute([$team_name, $description, $team_id, $manager_id]);
                // Clear existing members (simplifies the update process)
                $conn->prepare("DELETE FROM TeamMembers WHERE team_id = ?")->execute([$team_id]);
            } else {
                // Create new team
                $ins = $conn->prepare("INSERT INTO Teams (company_id, team_name, manager_id, description, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())");
                $ins->execute([$_SESSION['company_id'] ?? null, $team_name, $manager_id, $description]);
                $team_id = $conn->lastInsertId();
            }

            // Add members
            foreach ($members as $uid) {
                // Ensure member doesn't exist
                $chk = $conn->prepare("SELECT 1 FROM TeamMembers WHERE team_id = ? AND user_id = ? LIMIT 1");
                $chk->execute([$team_id, $uid]);
                if (!$chk->fetchColumn()) {
                    $add = $conn->prepare("INSERT INTO TeamMembers (team_id, user_id, joined_at) VALUES (?, ?, NOW())");
                    $add->execute([$team_id, $uid]);
                }
            }

            $conn->commit();
            echo json_encode(["success" => true, "message" => "Team saved", "team_id" => $team_id]);
            break;

        // ----------------------------------------------------------------
        // ADD_TEAM_MEMBER
        // ----------------------------------------------------------------
        case 'ADD_TEAM_MEMBER':
            $team_id = $inputJson['team_id'] ?? $_POST['team_id'] ?? null;
            $user_id = $inputJson['user_id'] ?? $_POST['user_id'] ?? null;

            if (!$team_id || !$user_id) throw new Exception("team_id and user_id required");

            $chk = $conn->prepare("SELECT 1 FROM TeamMembers WHERE team_id = ? AND user_id = ? LIMIT 1");
            $chk->execute([$team_id, $user_id]);
            if ($chk->fetchColumn()) {
                echo json_encode(["success" => true, "message" => "Member already in team"]);
                break;
            }

            $ins = $conn->prepare("INSERT INTO TeamMembers (team_id, user_id, joined_at) VALUES (?, ?, NOW())");
            $ins->execute([$team_id, $user_id]);

            echo json_encode(["success" => true, "message" => "Member added"]);
            break;

        // ----------------------------------------------------------------
        // REMOVE_TEAM_MEMBER
        // ----------------------------------------------------------------
        case 'REMOVE_TEAM_MEMBER':
            $team_id = $inputJson['team_id'] ?? $_POST['team_id'] ?? null;
            $user_id = $inputJson['user_id'] ?? $_POST['user_id'] ?? null;

            if (!$team_id || !$user_id) throw new Exception("team_id and user_id required");

            $del = $conn->prepare("DELETE FROM TeamMembers WHERE team_id = ? AND user_id = ?");
            $del->execute([$team_id, $user_id]);

            echo json_encode(["success" => true, "message" => "Member removed"]);
            break;

        // ----------------------------------------------------------------
        // DELETE_TEAM
        // ----------------------------------------------------------------
        case 'DELETE_TEAM':
            $team_id = $inputJson['team_id'] ?? $_POST['team_id'] ?? null;
            $manager_id = $_SESSION['user_id'] ?? null;

            if (!$team_id) throw new Exception("team_id required");

            $conn->beginTransaction();
            // Remove all members
            $conn->prepare("DELETE FROM TeamMembers WHERE team_id = ?")->execute([$team_id]);
            // Remove project assignments
            $conn->prepare("DELETE FROM ProjectAssignments WHERE team_id = ?")->execute([$team_id]);
            // Delete the team (only if current user is the manager)
            $del = $conn->prepare("DELETE FROM Teams WHERE team_id = ? AND manager_id = ?");
            $del->execute([$team_id, $manager_id]);
            $conn->commit();

            echo json_encode(["success" => true, "message" => "Team deleted"]);
            break;

        // ----------------------------------------------------------------
        // ASSIGN_TEAM_TO_PROJECT
        // Insert or update project assignment row for team
        // ----------------------------------------------------------------
        case 'ASSIGN_TEAM_TO_PROJECT':
            $project_id = $inputJson['project_id'] ?? $_POST['project_id'] ?? null;
            $team_id = $inputJson['team_id'] ?? $_POST['team_id'] ?? null;
            $role_on_project = $inputJson['role_on_project'] ?? $_POST['role_on_project'] ?? 'Contributor';

            if (!$project_id || !$team_id) throw new Exception("project_id and team_id required");

            // Check project exists
            $pChk = $conn->prepare("SELECT 1 FROM Projects WHERE project_id = ? LIMIT 1");
            $pChk->execute([$project_id]);
            if (!$pChk->fetchColumn()) throw new Exception("Project does not exist");

            // Avoid duplicate
            $chk = $conn->prepare("SELECT 1 FROM ProjectAssignments WHERE project_id = ? AND team_id = ? LIMIT 1");
            $chk->execute([$project_id, $team_id]);
            if ($chk->fetchColumn()) {
                $upd = $conn->prepare("UPDATE ProjectAssignments SET role_on_project = ?, assigned_at = NOW() WHERE project_id = ? AND team_id = ?");
                $upd->execute([$role_on_project, $project_id, $team_id]);
                echo json_encode(["success" => true, "message" => "Team assignment updated"]);
                break;
            }

            $ins = $conn->prepare("INSERT INTO ProjectAssignments (project_id, team_id, assigned_at, role_on_project) VALUES (?, ?, NOW(), ?)");
            $ins->execute([$project_id, $team_id, $role_on_project]);
            echo json_encode(["success" => true, "message" => "Team assigned to project"]);
            break;


        default:
            echo json_encode(["success" => false, "error" => "Invalid action: " . $action]);
            break;
    }

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>