<?php
header('Content-Type: application/json');
echo json_encode([
  ['project_id'=>101,'project_name'=>'Website Redesign','manager_name'=>'Alice Johnson','status'=>'Active','completion_percentage'=>42],
  ['project_id'=>102,'project_name'=>'Mobile App','manager_name'=>'Bob Smith','status'=>'Pending','completion_percentage'=>5],
  ['project_id'=>103,'project_name'=>'API Integration','manager_name'=>'Carla Gomez','status'=>'On Hold','completion_percentage'=>60]
], JSON_PRETTY_PRINT);
