--
-- RemoteTeamPro MySQL Database Schema (Merged with migrations)
-- Single, complete schema file
--

-- 1. Companies Table
CREATE TABLE Companies (
    company_id INT AUTO_INCREMENT PRIMARY KEY,
    company_name VARCHAR(255) NOT NULL UNIQUE,
    services TEXT NULL,
    admin_key VARCHAR(255) NULL,
    admin_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- 2. Users Table
CREATE TABLE Users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    role ENUM('Admin', 'Manager', 'Employee', 'Client') NOT NULL,
    status ENUM('Active', 'Inactive', 'Suspended') NOT NULL DEFAULT 'Active',
    profile_picture_url VARCHAR(500) NULL,
    contact_number VARCHAR(20) NULL,

    -- Migration additions
    email_verified TINYINT(1) NOT NULL DEFAULT 0,
    otp VARCHAR(6) NULL,
    otp_expiry DATETIME NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_company FOREIGN KEY (company_id) REFERENCES Companies(company_id) ON DELETE CASCADE
);

-- Index for OTP verification
CREATE INDEX idx_user_otp ON Users(email, otp, otp_expiry);

-- Link admin_user_id to Users
ALTER TABLE Companies
ADD CONSTRAINT fk_companies_admin_user
FOREIGN KEY (admin_user_id) REFERENCES Users(user_id)
ON DELETE SET NULL;

-- 3. Projects Table
CREATE TABLE Projects (
    project_id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    project_name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    manager_id INT NULL,
    client_id INT NULL,
    status ENUM('Pending', 'Active', 'On Hold', 'Completed', 'Cancelled') NOT NULL DEFAULT 'Pending',
    deadline DATE NULL,
    completion_percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    budget_allocated DECIMAL(15,2) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_project_company FOREIGN KEY (company_id) REFERENCES Companies(company_id) ON DELETE CASCADE,
    CONSTRAINT fk_project_manager FOREIGN KEY (manager_id) REFERENCES Users(user_id) ON DELETE SET NULL,
    CONSTRAINT fk_project_client FOREIGN KEY (client_id) REFERENCES Users(user_id) ON DELETE SET NULL
);

-- 4. Teams Table
CREATE TABLE Teams (
    team_id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    team_name VARCHAR(255) NOT NULL,
    manager_id INT NOT NULL,
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_team_company FOREIGN KEY (company_id) REFERENCES Companies(company_id) ON DELETE CASCADE,
    CONSTRAINT fk_team_manager FOREIGN KEY (manager_id) REFERENCES Users(user_id) ON DELETE CASCADE
);

-- 5. TeamMembers Table
CREATE TABLE TeamMembers (
    team_member_id INT AUTO_INCREMENT PRIMARY KEY,
    team_id INT NOT NULL,
    user_id INT NOT NULL,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_teammember_team FOREIGN KEY (team_id) REFERENCES Teams(team_id) ON DELETE CASCADE,
    CONSTRAINT fk_teammember_user FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE,
    UNIQUE (team_id, user_id)
);

-- 6. ProjectAssignments Table
CREATE TABLE ProjectAssignments (
    assignment_id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    team_id INT NULL,
    user_id INT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    role_on_project VARCHAR(100) NULL,
    CONSTRAINT fk_assignment_project FOREIGN KEY (project_id) REFERENCES Projects(project_id) ON DELETE CASCADE,
    CONSTRAINT fk_assignment_team FOREIGN KEY (team_id) REFERENCES Teams(team_id) ON DELETE CASCADE,
    CONSTRAINT fk_assignment_user FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE,
    CONSTRAINT chk_team_or_user CHECK (team_id IS NOT NULL OR user_id IS NOT NULL)
);

-- 7. Tasks Table
CREATE TABLE Tasks (
    task_id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    assigned_to_user_id INT NOT NULL,
    task_name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    status ENUM('To Do', 'In Progress', 'Under Review', 'Completed', 'Blocked') NOT NULL DEFAULT 'To Do',
    priority ENUM('Low', 'Medium', 'High', 'Urgent') NOT NULL DEFAULT 'Medium',
    due_date DATE NULL,
    estimated_hours DECIMAL(5,2) NULL,
    actual_hours DECIMAL(5,2) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_task_project FOREIGN KEY (project_id) REFERENCES Projects(project_id) ON DELETE CASCADE,
    CONSTRAINT fk_task_assigned_user FOREIGN KEY (assigned_to_user_id) REFERENCES Users(user_id) ON DELETE CASCADE
);

-- 8. Attendance Table
CREATE TABLE Attendance (
    attendance_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    attendance_date DATE NOT NULL,
    check_in_time TIME NULL,
    check_out_time TIME NULL,
    hours_worked DECIMAL(5,2) NULL,
    status ENUM('Present', 'Absent', 'Leave', 'Holiday') NOT NULL DEFAULT 'Present',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_attendance_user FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE,
    UNIQUE (user_id, attendance_date)
);

-- 9. Timesheets Table
CREATE TABLE Timesheets (
    timesheet_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    task_id INT NOT NULL,
    date DATE NOT NULL,
    hours_logged DECIMAL(5,2) NOT NULL,
    description TEXT NULL,
    status ENUM('Pending Approval', 'Approved', 'Rejected') NOT NULL DEFAULT 'Pending Approval',
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    approved_by_manager_id INT NULL,
    approved_at TIMESTAMP NULL,
    CONSTRAINT fk_timesheet_user FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_timesheet_task FOREIGN KEY (task_id) REFERENCES Tasks(task_id) ON DELETE CASCADE,
    CONSTRAINT fk_timesheet_approver FOREIGN KEY (approved_by_manager_id) REFERENCES Users(user_id) ON DELETE SET NULL
);

-- 10. Messages Table
CREATE TABLE Messages (
    message_id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    recipient_id INT NOT NULL,
    message_content TEXT NOT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_read BOOLEAN NOT NULL DEFAULT FALSE,
    CONSTRAINT fk_message_sender FOREIGN KEY (sender_id) REFERENCES Users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_message_recipient FOREIGN KEY (recipient_id) REFERENCES Users(user_id) ON DELETE CASCADE
);

-- 11. ClientRequests Table
CREATE TABLE ClientRequests (
    request_id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    project_name VARCHAR(255) NOT NULL,
    project_type VARCHAR(100) NULL,
    description TEXT NOT NULL,
    technology_stack VARCHAR(500) NULL,
    budget_allocated DECIMAL(15,2) NULL,
    requested_date DATE NOT NULL,
    status ENUM('New', 'Under Review', 'Approved', 'Rejected', 'Converted to Project') NOT NULL DEFAULT 'New',
    manager_id INT NULL,
    admin_notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_clientrequest_client FOREIGN KEY (client_id) REFERENCES Users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_clientrequest_manager FOREIGN KEY (manager_id) REFERENCES Users(user_id) ON DELETE SET NULL
);

-- 12. Skills Table
CREATE TABLE Skills (
    skill_id INT AUTO_INCREMENT PRIMARY KEY,
    skill_name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT NULL
);

-- 13. UserSkills Table
CREATE TABLE UserSkills (
    user_skill_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    skill_id INT NOT NULL,
    proficiency_level ENUM('Beginner', 'Intermediate', 'Advanced', 'Expert') NULL,
    CONSTRAINT fk_userskill_user FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_userskill_skill FOREIGN KEY (skill_id) REFERENCES Skills(skill_id) ON DELETE CASCADE,
    UNIQUE (user_id, skill_id)
);

-- 14. ActivityLog Table
CREATE TABLE ActivityLog (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action_type VARCHAR(100) NOT NULL,
    details TEXT NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45) NULL,
    CONSTRAINT fk_activitylog_user FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE SET NULL
);

-- 15. UserOTPs Table
CREATE TABLE UserOTPs (
    otp_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    email VARCHAR(255) NULL,
    company_id INT NULL,
    otp_code VARCHAR(6) NOT NULL,
    purpose ENUM('register','change_email') NOT NULL,
    used TINYINT(1) NOT NULL DEFAULT 0,
    expiry DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_userotps_user FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_userotps_company FOREIGN KEY (company_id) REFERENCES Companies(company_id) ON DELETE CASCADE
);

-- 16. UserRegistrationOTP Table
CREATE TABLE UserRegistrationOTP (
    registration_id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    otp VARCHAR(6) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    user_data JSON NOT NULL,
    verified TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_otp (otp),
    INDEX idx_expires_at (expires_at)
);

-- 17. Password Reset Tokens
CREATE TABLE password_reset_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    token VARCHAR(6) NOT NULL,
    expiry DATETIME NOT NULL,
    used BOOLEAN DEFAULT 0,
    verified TINYINT(1) DEFAULT 0 NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_token (email, token),
    INDEX idx_expiry (expiry)
);

-- 18. Contact Messages
CREATE TABLE contact_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    company_email VARCHAR(150) NOT NULL,
    topic VARCHAR(100) NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


ALTER TABLE ClientRequests
ADD COLUMN expected_date DATE NULL AFTER description,
MODIFY COLUMN budget_allocated DECIMAL(10,2) NULL;

SELECT c.*, u.company_id
FROM ClientRequests c
JOIN Users u ON c.client_id = u.user_id;



ALTER TABLE clientrequests 
MODIFY status ENUM('Pending', 'Approved', 'Rejected') NOT NULL DEFAULT 'Pending';

UPDATE clientrequests SET status = 'Pending' WHERE status = 1 OR status = 0;


ALTER TABLE ClientRequests
ADD COLUMN review_message TEXT NULL,
ADD COLUMN reviewed_by INT NULL;


ALTER TABLE Projects
ADD COLUMN technology_stack VARCHAR(500) NULL AFTER budget_allocated;

ALTER TABLE ClientRequests ADD COLUMN company_id INT NULL;

UPDATE ClientRequests cr
JOIN Users u ON cr.client_id = u.user_id
SET cr.company_id = u.company_id;

ALTER TABLE ClientRequests
ADD CONSTRAINT fk_clientrequests_company FOREIGN KEY (company_id) REFERENCES Companies(company_id) ON DELETE SET NULL;



ALTER TABLE Projects
ADD COLUMN client_request_id INT NULL,
ADD CONSTRAINT fk_projects_client_request
FOREIGN KEY (client_request_id) REFERENCES ClientRequests(request_id)
ON DELETE SET NULL;


ALTER TABLE ClientRequests 

MODIFY status ENUM('Pending', 'Approved', 'Rejected', 'Converted to Project') NOT NULL DEFAULT 'Pending';


-- 1. Add the start_date column
ALTER TABLE Projects
ADD COLUMN start_date DATE NULL;

-- 2. Add the end_date column
ALTER TABLE Projects
ADD COLUMN end_date DATE NULL;

ALTER TABLE Companies ADD COLUMN auto_checkout_time TIME NULL DEFAULT '19:00:00';



ALTER TABLE Companies
ADD COLUMN company_email VARCHAR(255) NULL AFTER company_name,
ADD COLUMN company_phone VARCHAR(50) NULL AFTER company_email,
ADD COLUMN company_address VARCHAR(255) NULL AFTER company_phone;


-- 1) Conversations (1:1 or group/team)
CREATE TABLE Conversations (
    conversation_id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    title VARCHAR(255) NULL, -- optional for group/team chats
    is_group TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT NULL, -- allow NULL because of ON DELETE SET NULL
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_convo_company FOREIGN KEY (company_id)
        REFERENCES Companies(company_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_convo_creator FOREIGN KEY (created_by)
        REFERENCES Users(user_id)
        ON DELETE SET NULL
) ENGINE=InnoDB;

-- 2) ConversationParticipants
CREATE TABLE ConversationParticipants (
    participant_id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    user_id INT NOT NULL,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_admin TINYINT(1) DEFAULT 0,
    last_read_at TIMESTAMP NULL,
    CONSTRAINT fk_participant_convo FOREIGN KEY (conversation_id)
        REFERENCES Conversations(conversation_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_participant_user FOREIGN KEY (user_id)
        REFERENCES Users(user_id)
        ON DELETE CASCADE,
    UNIQUE (conversation_id, user_id)
) ENGINE=InnoDB;

-- 3) ConversationMessages (store messages)
CREATE TABLE ConversationMessages (
    message_id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    sender_id INT NOT NULL,
    content TEXT NOT NULL,
    content_type ENUM('text','system','file') NOT NULL DEFAULT 'text',
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_deleted TINYINT(1) DEFAULT 0,
    CONSTRAINT fk_convmsg_convo FOREIGN KEY (conversation_id)
        REFERENCES Conversations(conversation_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_convmsg_sender FOREIGN KEY (sender_id)
        REFERENCES Users(user_id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

-- 4) MessageReads: per-user read status
CREATE TABLE MessageReads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message_id INT NOT NULL,
    user_id INT NOT NULL,
    read_at TIMESTAMP NULL,
    CONSTRAINT fk_mread_message FOREIGN KEY (message_id)
        REFERENCES ConversationMessages(message_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_mread_user FOREIGN KEY (user_id)
        REFERENCES Users(user_id)
        ON DELETE CASCADE,
    UNIQUE (message_id, user_id)
) ENGINE=InnoDB;

-- 5) Notifications table
CREATE TABLE Notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    link VARCHAR(500) NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notification_user FOREIGN KEY (user_id)
        REFERENCES Users(user_id)
        ON DELETE CASCADE
) ENGINE=InnoDB;


DELETE FROM ConversationParticipants WHERE conversation_id IN (SELECT conversation_id FROM Conversations WHERE title IN ('Manager chat','Admin chat'));
DELETE FROM Conversations WHERE title IN ('Manager chat','Admin chat');


-- Conversations table
CREATE TABLE IF NOT EXISTS conversations (
  conversation_id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  is_group TINYINT(1) DEFAULT 0,
  company_id INT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_convo_company FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE
);

-- Conversation participants
CREATE TABLE IF NOT EXISTS conversation_participants (
  id INT AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT NOT NULL,
  user_id INT NOT NULL,
  joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (conversation_id) REFERENCES conversations(conversation_id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- Messages table
CREATE TABLE IF NOT EXISTS messages (
  message_id INT AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT NOT NULL,
  sender_id INT NOT NULL,
  message TEXT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (conversation_id) REFERENCES conversations(conversation_id) ON DELETE CASCADE,
  FOREIGN KEY (sender_id) REFERENCES users(user_id) ON DELETE CASCADE
);


ALTER TABLE conversations 
ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP 
ON UPDATE CURRENT_TIMESTAMP;


ALTER TABLE conversations 
ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP 
ON UPDATE CURRENT_TIMESTAMP;


ALTER TABLE messages 
ADD COLUMN conversation_id INT(11) NOT NULL AFTER message_id;


ALTER TABLE UserSkills
ADD COLUMN verification_status ENUM('Pending', 'Verified', 'Rejected') DEFAULT 'Pending',
ADD COLUMN verified_by INT NULL,
ADD COLUMN verified_at TIMESTAMP NULL,
ADD CONSTRAINT fk_userskill_verifiedby FOREIGN KEY (verified_by) REFERENCES Users(user_id) ON DELETE SET NULL;





ALTER TABLE messagereads DROP FOREIGN KEY fk_mread_message;


DROP TABLE IF EXISTS conversationmessages;


ALTER TABLE messagereads
ADD CONSTRAINT fk_mread_message
FOREIGN KEY (message_id) REFERENCES messages (message_id)
ON DELETE CASCADE;



DROP TABLE IF EXISTS conversationparticipants;


DROP TABLE IF EXISTS MessageReads;
