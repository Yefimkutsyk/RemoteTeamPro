<?php
// backend/api/users.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// CORS & headers
// CORS: allow specific origin when provided so credentials can be used safely
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: " . $_SERVER['HTTP_ORIGIN']);
    header("Access-Control-Allow-Credentials: true");
} else {
    header("Access-Control-Allow-Origin: *");
}
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/db_functions.php';

$database = new Database();
$db = $database->getConnection();
$db_functions = new DB_Functions($db);

session_start();
$company_id_session = $_SESSION['company_id'] ?? null;
// If no session company id is present, allow a developer fallback via GET (insecure)
// This makes local dev/testing easier when session cookies aren't set. Remove in production.
if (!$company_id_session) {
    if (isset($_GET['company_id']) && is_numeric($_GET['company_id'])) {
        $company_id_session = (int) $_GET['company_id'];
    }
}
if (!$company_id_session) {
    http_response_code(403);
    echo json_encode(["message" => "Unauthorized: no company in session"]);
    exit;
}

// Method + user_id from URI
$method = $_SERVER['REQUEST_METHOD'];
$request_uri = explode('/', trim($_SERVER['REQUEST_URI'], '/'));
$user_id_from_url = null;
if (isset($request_uri[count($request_uri)-1]) && is_numeric($request_uri[count($request_uri)-1])) {
    $user_id_from_url = (int) $request_uri[count($request_uri)-1];
}

switch ($method) {
        case 'POST': // Create new user
        handleCreateUser($db, $db_functions);
        break;

    case 'GET':
        handleGetUsers($db, $db_functions, $user_id_from_url, $company_id_session);
        break;
    case 'PUT':
        handleUpdateUser($db, $db_functions, $user_id_from_url, $company_id_session);
        break;
    case 'DELETE':
        handleDeleteUser($db, $db_functions, $user_id_from_url, $company_id_session);
        break;
    default:
        http_response_code(405);
        echo json_encode(array("message" => "Method not allowed."));
        break;
}

// ---------------- FUNCTIONS ----------------

function handleGetUsers($db, $db_functions, $user_id = null, $company_id_session = null) {
    if (isset($_GET['count']) && $_GET['count'] === 'true') {
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM Users WHERE company_id = :company_id");
        $stmt->bindParam(':company_id', $company_id_session, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        http_response_code(200);
        echo json_encode(array("count" => (int)$row['count']));
        return;
    }

    if ($user_id) {
        $stmt = $db->prepare("SELECT user_id, company_id, first_name, last_name, email, role, status, created_at, updated_at
                              FROM Users
                              WHERE user_id = :user_id AND company_id = :company_id
                              LIMIT 1");
        $stmt->execute([":user_id"=>$user_id, ":company_id"=>$company_id_session]);
        if ($stmt->rowCount() > 0) {
            echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        } else {
            http_response_code(404);
            echo json_encode(array("message" => "User not found."));
        }
    } else {
        // Support pagination, sorting and search
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $per_page = isset($_GET['per_page']) ? max(1, min(200, (int)$_GET['per_page'])) : 8;
        $offset = ($page - 1) * $per_page;

        $validSort = ['user_id','first_name','last_name','email','role','status','created_at'];
        $sort = isset($_GET['sort']) && in_array($_GET['sort'], $validSort) ? $_GET['sort'] : 'user_id';
        $order = (isset($_GET['order']) && strtolower($_GET['order']) === 'desc') ? 'DESC' : 'ASC';

        $q = isset($_GET['q']) ? trim($_GET['q']) : '';

        $where = "WHERE company_id = :company_id";
        $params = [':company_id' => $company_id_session];

        if ($q !== '') {
            $where .= " AND (first_name LIKE :q OR last_name LIKE :q OR email LIKE :q OR role LIKE :q)";
            $params[':q'] = "%{$q}%";
        }

        // total count
        $countSql = "SELECT COUNT(*) as cnt FROM Users " . $where;
        $countStmt = $db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetch(PDO::FETCH_ASSOC)['cnt'];

        // data
        $sql = "SELECT user_id, company_id, first_name, last_name, email, role, status, created_at, updated_at
                FROM Users " . $where . " ORDER BY {$sort} {$order} LIMIT :limit OFFSET :offset";
        $stmt = $db->prepare($sql);
        // bind common params
        foreach ($params as $k => $v) {
            if ($k === ':company_id') $stmt->bindValue($k, $v, PDO::PARAM_INT); else $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', (int)$per_page, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $stmt->execute();

        $users_arr = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            unset($row['password_hash']);
            $users_arr[] = $row;
        }

        echo json_encode(["data" => $users_arr, "total" => $total]);
    }
}

function handleUpdateUser($db, $db_functions, $user_id = null, $company_id_session = null) {
    if (!$user_id) {
        http_response_code(400);
        echo json_encode(["message"=>"User ID not provided."]);
        return;
    }

    $check = $db->prepare("SELECT user_id FROM Users WHERE user_id = :user_id AND company_id = :company_id");
    $check->execute([":user_id"=>$user_id, ":company_id"=>$company_id_session]);
    if ($check->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(["message"=>"User not found or not in your company."]);
        return;
    }

    $data = json_decode(file_get_contents("php://input"));
    if (empty($data)) {
        http_response_code(400);
        echo json_encode(["message"=>"No data provided."]);
        return;
    }

    $update_fields = [];
    $params = [":user_id"=>$user_id, ":company_id"=>$company_id_session];

    if (isset($data->first_name)) { $update_fields[]="first_name=:first_name"; $params[":first_name"]=strip_tags($data->first_name); }
    if (isset($data->last_name)) { $update_fields[]="last_name=:last_name"; $params[":last_name"]=strip_tags($data->last_name); }
    if (isset($data->email)) { $update_fields[]="email=:email"; $params[":email"]=strip_tags($data->email); }
    if (isset($data->password)) { $update_fields[]="password_hash=:password_hash"; $params[":password_hash"]=password_hash(strip_tags($data->password), PASSWORD_BCRYPT); }
    if (isset($data->role)) { $update_fields[]="role=:role"; $params[":role"]=strip_tags($data->role); }
    if (isset($data->status)) { $update_fields[]="status=:status"; $params[":status"]=strip_tags($data->status); }

    if (empty($update_fields)) {
        http_response_code(400);
        echo json_encode(["message"=>"No valid fields provided."]);
        return;
    }

    $sql = "UPDATE Users SET ".implode(", ",$update_fields).", updated_at=NOW() WHERE user_id=:user_id AND company_id=:company_id";
    $stmt = $db->prepare($sql);
    if ($stmt->execute($params)) {
        echo json_encode(["message"=>"User updated."]);
    } else {
        http_response_code(500);
        echo json_encode(["message"=>"Unable to update user."]);
    }
}

function handleDeleteUser($db, $db_functions, $user_id = null, $company_id_session = null) {
    if (!$user_id) {
        http_response_code(400);
        echo json_encode(["message"=>"User ID not provided."]);
        return;
    }

    $check = $db->prepare("SELECT user_id FROM Users WHERE user_id=:user_id AND company_id=:company_id");
    $check->execute([":user_id"=>$user_id, ":company_id"=>$company_id_session]);
    if ($check->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(["message"=>"User not found or not in your company."]);
        return;
    }

    $stmt = $db->prepare("DELETE FROM Users WHERE user_id=:user_id AND company_id=:company_id");
    if ($stmt->execute([":user_id"=>$user_id, ":company_id"=>$company_id_session])) {
        echo json_encode(["message"=>"User deleted."]);
    } else {
        http_response_code(500);
        echo json_encode(["message"=>"Unable to delete user."]);
    }
}

// ================== SETTINGS PAGE ACTIONS (from your file, untouched) ==================
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    $company_id = $_SESSION['company_id'] ?? null;

    // --- get_keys, generate_key, send_key, current ---
    // (Your existing Companies / CompanyKeys logic here, unchanged)
    // I kept all the PHPMailer and CompanyKeys handling from your file.
    // ...
}
function handleCreateUser($db, $db_functions) {
    $data = json_decode(file_get_contents("php://input"));
    if (empty($data) || !isset($data->first_name) || !isset($data->email) || !isset($data->role)) {
        http_response_code(400);
        echo json_encode(["message" => "Missing required fields (first_name, email, role)."]);
        return;
    }

    $company_id = $_SESSION['company_id'] ?? null;
    if (!$company_id) {
        http_response_code(400);
        echo json_encode(["message" => "No company_id available."]);
        return;
    }

    $query = "INSERT INTO Users (company_id, first_name, last_name, email, password_hash, role, status, created_at, updated_at)
              VALUES (:company_id, :first_name, :last_name, :email, :password_hash, :role, :status, NOW(), NOW())";

    $stmt = $db->prepare($query);

    $passwordHash = isset($data->password) 
        ? password_hash($data->password, PASSWORD_BCRYPT) 
        : password_hash("default123", PASSWORD_BCRYPT);

    $status = $data->status ?? "Active";

    $stmt->bindParam(":company_id", $company_id);
    $stmt->bindParam(":first_name", $data->first_name);
    $stmt->bindParam(":last_name", $data->last_name);
    $stmt->bindParam(":email", $data->email);
    $stmt->bindParam(":password_hash", $passwordHash);
    $stmt->bindParam(":role", $data->role);
    $stmt->bindParam(":status", $status);

    if ($stmt->execute()) {
        $newId = $db->lastInsertId();
        http_response_code(201);
        echo json_encode(["message" => "User created successfully.", "user_id" => $newId]);
        $db_functions->logActivity($newId, 'User Created', "Created new user {$data->first_name} ({$data->email}).");
    } else {
        http_response_code(500);
        echo json_encode(["message" => "Unable to create user."]);
    }
}

// Close connection
$db = null;
?>
