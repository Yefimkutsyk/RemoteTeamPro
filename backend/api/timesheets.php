<?php
session_start();

header("Content-Type: application/json; charset=UTF-8");
$origin = $_SERVER['HTTP_ORIGIN'] ?? 'http://localhost';
$allowed_origins = ['http://localhost', 'http://127.0.0.1', 'http://localhost:3000'];
if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Credentials: true");
}
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

require_once(__DIR__ . '/../config/database.php');
$db = (new Database())->getConnection();

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) sendJson(["message"=>"Authentication required."],401);

$q = $db->prepare("SELECT company_id, role FROM Users WHERE user_id=:u");
$q->execute([":u"=>$user_id]);
$user=$q->fetch(PDO::FETCH_ASSOC);
if(!$user) sendJson(["message"=>"Invalid session"],401);
$company_id=$user['company_id']; $role=$user['role'];

$action=$_GET['action']??'';
$raw=file_get_contents("php://input");
$data=$raw?json_decode($raw):null;

switch($action){
    case 'get_tasks': getTasks($db,$company_id,$user_id,$role); break;
    case 'get_timesheets': getTimesheets($db,$company_id,$user_id,$role); break;
    case 'submit_timesheet': if($_SERVER['REQUEST_METHOD']==='POST') submitTimesheet($db,$user_id,$data); break;
    case 'update_status': if($_SERVER['REQUEST_METHOD']==='POST') updateStatus($db,$user_id,$role,$data); break;
    case 'get_summary': getSummary($db,$company_id,$role); break;
    default: sendJson(["message"=>"Invalid action"],400);
}

function getTasks($db,$cid,$uid,$role){
    if($role==='Employee'){
        $s=$db->prepare("SELECT t.task_id,t.task_name,p.project_name,p.project_id 
                         FROM Tasks t JOIN Projects p ON t.project_id=p.project_id 
                         WHERE t.assigned_to_user_id=:u AND p.company_id=:c ORDER BY p.project_name");
        $s->execute([":u"=>$uid,":c"=>$cid]);
    }else{
        $s=$db->prepare("SELECT t.task_id,t.task_name,p.project_name,p.project_id,
                         CONCAT(u.first_name,' ',u.last_name) assigned_to,u.user_id assigned_to_user_id
                         FROM Tasks t JOIN Projects p ON t.project_id=p.project_id
                         JOIN Users u ON t.assigned_to_user_id=u.user_id
                         WHERE p.company_id=:c ORDER BY p.project_name");
        $s->execute([":c"=>$cid]);
    }
    sendJson($s->fetchAll(PDO::FETCH_ASSOC));
}

function getTimesheets($db,$cid,$uid,$role){
    if($role==='Employee'){
        $sql="SELECT ts.*,t.task_name,p.project_name,p.project_id 
              FROM Timesheets ts 
              JOIN Tasks t ON ts.task_id=t.task_id 
              JOIN Projects p ON t.project_id=p.project_id 
              WHERE ts.user_id=:u ORDER BY ts.date DESC";
        $st=$db->prepare($sql);$st->execute([":u"=>$uid]);
    }else{
        $sql="SELECT ts.*,p.project_name,p.project_id,t.task_name,
                     CONCAT(u.first_name,' ',u.last_name) employee_name
              FROM Timesheets ts 
              JOIN Tasks t ON ts.task_id=t.task_id 
              JOIN Projects p ON t.project_id=p.project_id
              JOIN Users u ON ts.user_id=u.user_id
              WHERE p.company_id=:c ORDER BY ts.date DESC";
        $st=$db->prepare($sql);$st->execute([":c"=>$cid]);
    }
    sendJson($st->fetchAll(PDO::FETCH_ASSOC));
}

function submitTimesheet($db,$uid,$d){
    if(!$d||!$d->task_id||!$d->date||!$d->hours_logged) sendJson(["message"=>"Missing fields"],400);
    $st=$db->prepare("INSERT INTO Timesheets(user_id,task_id,date,hours_logged,description,status)
                      VALUES(:u,:t,:d,:h,:x,'Pending Approval')");
    $st->execute([":u"=>$uid,":t"=>$d->task_id,":d"=>$d->date,":h"=>$d->hours_logged,":x"=>$d->description]);
    sendJson(["message"=>"Timesheet submitted successfully."],201);
}

function updateStatus($db,$uid,$role,$d){
    if(!in_array($role,['Manager','Admin'])) sendJson(["message"=>"Unauthorized"],403);
    if(!$d||!$d->timesheet_id||!$d->status) sendJson(["message"=>"Missing fields"],400);
    $s=$db->prepare("UPDATE Timesheets SET status=:s,approved_by_manager_id=:m,approved_at=NOW() WHERE timesheet_id=:id");
    $s->execute([":s"=>$d->status,":m"=>$uid,":id"=>$d->timesheet_id]);
    sendJson(["message"=>"Status updated successfully."]);
}

function getSummary($db,$cid,$role){
    if(!in_array($role,['Manager','Admin'])) sendJson(["message"=>"Unauthorized"],403);
    $e=$db->prepare("SELECT CONCAT(u.first_name,' ',u.last_name) employee_name,COALESCE(SUM(ts.hours_logged),0) total_hours
                     FROM Timesheets ts JOIN Users u ON ts.user_id=u.user_id 
                     JOIN Tasks t ON ts.task_id=t.task_id 
                     JOIN Projects p ON t.project_id=p.project_id
                     WHERE p.company_id=:c GROUP BY u.user_id");
    $e->execute([":c"=>$cid]);
    $p=$db->prepare("SELECT p.project_name,COALESCE(SUM(ts.hours_logged),0) total_hours
                     FROM Timesheets ts JOIN Tasks t ON ts.task_id=t.task_id 
                     JOIN Projects p ON t.project_id=p.project_id 
                     WHERE p.company_id=:c GROUP BY p.project_id");
    $p->execute([":c"=>$cid]);
    sendJson(["byEmployee"=>$e->fetchAll(PDO::FETCH_ASSOC),"byProject"=>$p->fetchAll(PDO::FETCH_ASSOC)]);
}

function sendJson($d,$s=200){http_response_code($s);echo json_encode($d);exit;}
?>
