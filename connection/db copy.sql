-- 1. Schools
CREATE TABLE schools (
    school_id       INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(255) NOT NULL,
    address         VARCHAR(255),
    email           VARCHAR(100) UNIQUE,
    phone           VARCHAR(20),
    logo            VARCHAR(255), -- store file path or URL
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. Roles (depends on schools)
CREATE TABLE roles (
    role_id         INT AUTO_INCREMENT PRIMARY KEY,
    school_id       INT NOT NULL,
    role_name       VARCHAR(50) NOT NULL,
    UNIQUE (school_id, role_name),
    FOREIGN KEY (school_id) REFERENCES schools(school_id) ON DELETE CASCADE
);

-- 3. Users (depends on schools, roles)
CREATE TABLE users (
    user_id         INT AUTO_INCREMENT PRIMARY KEY,
    school_id       INT NOT NULL,
    role_id         INT NOT NULL,
    -- Teacher-specific fields
    first_name      VARCHAR(100),
    other_names     VARCHAR(100),
    initials        VARCHAR(20),
    username        VARCHAR(100) UNIQUE,
    email           VARCHAR(100),               -- system email
    personal_email  VARCHAR(100),               -- personal email
    phone_number    VARCHAR(20),
    gender          ENUM('Male','Female'),
    national_id     VARCHAR(50),
    tsc_number      VARCHAR(50),
    employee_number VARCHAR(50),
    address         VARCHAR(255),
    bio             TEXT,
    comments        TEXT,
    signature       VARCHAR(255),
    profile_picture VARCHAR(255),
    password_hash   VARCHAR(255) NOT NULL,
    status          ENUM('active','inactive') DEFAULT 'active',
    deleted_at      TIMESTAMP NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (school_id, email),
    FOREIGN KEY (school_id) REFERENCES schools(school_id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(role_id) ON DELETE RESTRICT
);

-- 4. Subjects (depends on schools)
CREATE TABLE subjects (
    subject_id      INT AUTO_INCREMENT PRIMARY KEY,
    school_id       INT NOT NULL,
    name            VARCHAR(100) NOT NULL,
    type            VARCHAR(50), -- e.g. compulsory, elective
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at      TIMESTAMP NULL,
    is_global BOOLEAN DEFAULT FALSE,
    FOREIGN KEY (school_id) REFERENCES schools(school_id) ON DELETE CASCADE
);

-- 5. Subject Papers (depends on schools, subjects)
CREATE TABLE subject_papers (
    paper_id        INT AUTO_INCREMENT PRIMARY KEY,
    school_id       INT NULL,
    subject_id      INT NOT NULL,
    paper_name      VARCHAR(50) NOT NULL, -- e.g. Paper 1, Paper 2
    description     TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    max_score DECIMAL(5,2) NULL,
    contribution_percentage DECIMAL(5,2) NULL,
    FOREIGN KEY (school_id) REFERENCES schools(school_id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(subject_id) ON DELETE CASCADE,
    UNIQUE (school_id, subject_id, paper_name)  -- avoid duplicate paper names in same school
);

-- 6. Classes (depends on schools)
CREATE TABLE classes (
    class_id        INT AUTO_INCREMENT PRIMARY KEY,
    school_id       INT NOT NULL,
    form_name       VARCHAR(100) NOT NULL,  -- e.g., Form 1, Grade 2
    description     TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (school_id) REFERENCES schools(school_id) ON DELETE CASCADE
);

-- 7. Streams (depends on schools, classes)
CREATE TABLE streams (
    stream_id       INT AUTO_INCREMENT PRIMARY KEY,
    school_id       INT NOT NULL,
    class_id        INT NOT NULL,
    stream_name     VARCHAR(100) NOT NULL,  -- e.g., East, A
    description     TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (school_id) REFERENCES schools(school_id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(class_id) ON DELETE CASCADE,
    UNIQUE (school_id, class_id, stream_name) -- avoid duplicates
);

-- 8. Students (depends on schools, classes, streams)
CREATE TABLE students (
    student_id          INT AUTO_INCREMENT PRIMARY KEY,
    school_id           INT NOT NULL,
    class_id            INT NOT NULL,
    stream_id           INT NOT NULL,
    admission_no        VARCHAR(50) NOT NULL,        -- ADMNO
    full_name           VARCHAR(150) NOT NULL,       -- NAME
    gender              ENUM('Male','Female') NOT NULL,
    upi                 VARCHAR(50),                 -- UPI
    date_of_admission   DATE,
    enrollment_form     VARCHAR(255),
    entry_position      VARCHAR(50),
    nhif                VARCHAR(50),
    kcpe_index          VARCHAR(50),
    kcpe_score          INT,
    kcpe_grade          VARCHAR(5),
    kcpe_year           YEAR,
    index_number        VARCHAR(50),
    dob                 DATE,
    birth_cert_number   VARCHAR(50),
    nationality         VARCHAR(50),
    place_of_birth      VARCHAR(100),
    previous_school     VARCHAR(150),
    primary_phone       VARCHAR(20),
    primary_phone_2     VARCHAR(20),
    secondary_phone     VARCHAR(20),
    secondary_phone_2   VARCHAR(20),
    guardian_name       VARCHAR(150),
    guardian_relation   VARCHAR(50),
    primary_school      VARCHAR(150),
    general_comments    TEXT,
    profile_picture     VARCHAR(255),
    enrolled_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at          TIMESTAMP NULL,
    UNIQUE (school_id, admission_no),
    FOREIGN KEY (school_id) REFERENCES schools(school_id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(class_id) ON DELETE CASCADE,
    FOREIGN KEY (stream_id) REFERENCES streams(stream_id) ON DELETE CASCADE
);

-- 9. Permissions (standalone, no dependency)
CREATE TABLE permissions (
    permission_id   INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    description     VARCHAR(255),
    is_global       BOOLEAN DEFAULT FALSE,
    UNIQUE (name)
);

-- 10. Role Permissions (depends on roles, permissions, schools)
CREATE TABLE role_permissions (
    role_permission_id INT AUTO_INCREMENT PRIMARY KEY,
    role_id            INT NOT NULL,
    permission_id      INT NOT NULL,
    school_id          INT,
    FOREIGN KEY (role_id) REFERENCES roles(role_id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(permission_id) ON DELETE CASCADE,
    FOREIGN KEY (school_id) REFERENCES schools(school_id) ON DELETE CASCADE,
    UNIQUE (role_id, permission_id, school_id)
);

-- 11. Remarks Rules (depends on schools)
CREATE TABLE remarks_rules (
    remark_id      INT AUTO_INCREMENT PRIMARY KEY,
    school_id      INT NOT NULL,
    min_score      DECIMAL(5,2) NULL,     -- e.g., 80
    max_score      DECIMAL(5,2) NULL,     -- e.g., 100
    grade          VARCHAR(5) NULL,       -- e.g., A, B
    position_from  INT NULL,              -- e.g., 1
    position_to    INT NULL,              -- e.g., 3
    remark_text    VARCHAR(255) NOT NULL, -- e.g., "Excellent work!"
    category       ENUM('principal','class_teacher','class_supervisor','subject_teacher') NOT NULL,
    class_id       INT NULL,              -- Optional: specific class
    stream_id      INT NULL,              -- Optional: specific stream
    subject_id     INT NULL,              -- Optional: specific subject
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (school_id) REFERENCES schools(school_id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(class_id) ON DELETE SET NULL,
    FOREIGN KEY (stream_id) REFERENCES streams(stream_id) ON DELETE SET NULL,
    FOREIGN KEY (subject_id) REFERENCES subjects(subject_id) ON DELETE SET NULL
);
-- 12. Exams (depends on schools, classes, subjects, subject_papers, users)
CREATE TABLE exams (
    exam_id         INT AUTO_INCREMENT PRIMARY KEY,
    school_id       INT NOT NULL,
    exam_name       VARCHAR(150) NOT NULL,
    status          ENUM('active','closed') DEFAULT 'active',
    class_id        INT NOT NULL,
    subject_id      INT NOT NULL,
    paper_id        INT NULL,
    created_by      INT NULL,          -- FIXED: must be NULLABLE for ON DELETE SET NULL
    min_subjects    INT,
    exam_type       VARCHAR(100),
    term            VARCHAR(50),

    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at      TIMESTAMP NULL,
    grading_system_id INT NOT NULL,
    FOREIGN KEY (school_id) REFERENCES schools(school_id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(class_id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(subject_id) ON DELETE CASCADE,
    FOREIGN KEY (paper_id) REFERENCES subject_papers(paper_id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (grading_system_id) REFERENCES grading_systems(grading_system_id) ON DELETE RESTRICT
);

-- 13. Results (depends on schools, exams, students, classes, streams, subjects, subject_papers, remarks_rules)
CREATE TABLE results (
    result_id        INT AUTO_INCREMENT PRIMARY KEY,
    school_id        INT NOT NULL,
    exam_id          INT NOT NULL,
    student_id       INT NOT NULL,
    class_id         INT NOT NULL,       -- e.g. Form 2
    stream_id        INT NOT NULL,       -- e.g. Form 2 East
    subject_id       INT NOT NULL,
    paper_id         INT NULL,
    score            DECIMAL(5,2),
    grade            VARCHAR(5),
    subject_teacher_remark_id INT NULL,
    subject_teacher_remark_text VARCHAR(255),
    class_teacher_remark_id INT NULL,
    class_teacher_remark_text VARCHAR(255),
    class_supervisor_remark_id INT NULL,
    class_supervisor_remark_text VARCHAR(255),
    principal_remark_id INT NULL,
    principal_remark_text VARCHAR(255),
    status           ENUM('pending','confirmed') DEFAULT 'pending',
    deleted_at       TIMESTAMP NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    -- Foreign keys
    FOREIGN KEY (school_id) REFERENCES schools(school_id) ON DELETE CASCADE,
    FOREIGN KEY (exam_id) REFERENCES exams(exam_id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(class_id) ON DELETE CASCADE,
    FOREIGN KEY (stream_id) REFERENCES streams(stream_id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(subject_id) ON DELETE CASCADE,
    FOREIGN KEY (paper_id) REFERENCES subject_papers(paper_id) ON DELETE CASCADE,
    FOREIGN KEY (subject_teacher_remark_id) REFERENCES remarks_rules(remark_id) ON DELETE SET NULL,
    FOREIGN KEY (class_teacher_remark_id) REFERENCES remarks_rules(remark_id) ON DELETE SET NULL,
    FOREIGN KEY (class_supervisor_remark_id) REFERENCES remarks_rules(remark_id) ON DELETE SET NULL,
    FOREIGN KEY (principal_remark_id) REFERENCES remarks_rules(remark_id) ON DELETE SET NULL,
    -- Prevent duplicate results for same exam/student/subject/paper
    UNIQUE (school_id, exam_id, student_id, subject_id, paper_id)
);
-- 15. Teacher Subjects (depends on schools, users, subjects, classes, streams)
CREATE TABLE teacher_subjects (
    teacher_subject_id INT AUTO_INCREMENT PRIMARY KEY,
    school_id          INT NOT NULL,
    user_id            INT NOT NULL,   -- teacher (from users table)
    subject_id         INT NOT NULL,   -- subject being taught
    class_id           INT NULL,       -- optional: assign per class
    stream_id          INT NULL,       -- optional: assign per stream
    academic_year      YEAR,           -- optional: track by year
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (school_id) REFERENCES schools(school_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(subject_id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(class_id) ON DELETE CASCADE,
    FOREIGN KEY (stream_id) REFERENCES streams(stream_id) ON DELETE CASCADE,
    UNIQUE (school_id, user_id, subject_id, class_id, stream_id, academic_year)
);

-- 16. Class Supervisors (depends on schools, users, classes)
CREATE TABLE class_supervisors (
    supervisor_id   INT AUTO_INCREMENT PRIMARY KEY,
    school_id       INT NOT NULL,
    user_id         INT NOT NULL,     -- teacher
    class_id        INT NOT NULL,     -- e.g. Form 2
    academic_year   YEAR NOT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (school_id) REFERENCES schools(school_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(class_id) ON DELETE CASCADE,
    UNIQUE (school_id, class_id, academic_year)  -- only one supervisor per class/year
);

-- 17. Class Teachers (depends on schools, users, classes, streams)
CREATE TABLE class_teachers (
    class_teacher_id INT AUTO_INCREMENT PRIMARY KEY,
    school_id        INT NOT NULL,
    user_id          INT NOT NULL,    -- teacher
    class_id         INT NOT NULL,
    stream_id        INT NOT NULL,    -- e.g. Form 2A
    academic_year    YEAR NOT NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (school_id) REFERENCES schools(school_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(class_id) ON DELETE CASCADE,
    FOREIGN KEY (stream_id) REFERENCES streams(stream_id) ON DELETE CASCADE,
    UNIQUE (school_id, stream_id, academic_year) -- one class teacher per stream/year
);

-- Additional tables

CREATE TABLE exam_subjects (
    exam_subject_id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    subject_id INT NOT NULL,
    class_id INT NOT NULL,
    use_papers TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (exam_id) REFERENCES exams(exam_id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(subject_id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(class_id),
    UNIQUE (exam_id, subject_id)
);

-- Create class_subjects table to manage subject assignments to classes
CREATE TABLE class_subjects (
    class_subject_id INT AUTO_INCREMENT PRIMARY KEY,
    class_id INT NOT NULL,
    subject_id INT NOT NULL,
    type ENUM('compulsory', 'elective') NOT NULL, -- Specifies if the subject is compulsory or elective for this class
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    use_papers TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (class_id) REFERENCES classes(class_id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(subject_id) ON DELETE CASCADE,
    UNIQUE (class_id, subject_id) -- Ensure a subject is assigned to a class only once
);

-- Create grading_systems table
CREATE TABLE grading_systems (
    grading_system_id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NULL,
    name VARCHAR(100) NOT NULL,
    is_default BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (school_id) REFERENCES schools(school_id) ON DELETE CASCADE,
    UNIQUE (school_id, name)
);

-- Create grading_rules table to store grade ranges and points
CREATE TABLE grading_rules (
    grading_rule_id INT AUTO_INCREMENT PRIMARY KEY,
    grading_system_id INT NOT NULL,
    grade VARCHAR(5) NOT NULL,
    min_score DECIMAL(5,2) NOT NULL,
    max_score DECIMAL(5,2) NOT NULL,
    points INT NOT NULL,
    description VARCHAR(255) NULL,
    FOREIGN KEY (grading_system_id) REFERENCES grading_systems(grading_system_id) ON DELETE CASCADE,
    UNIQUE (grading_system_id, grade)
);

CREATE TABLE exam_subjects_papers (
    exam_id INT NOT NULL,
    subject_id INT NOT NULL,
    paper_id INT NOT NULL,
    max_score DECIMAL(5,2) NOT NULL,
    PRIMARY KEY (exam_id, subject_id, paper_id),
    FOREIGN KEY (exam_id) REFERENCES exams(exam_id),
    FOREIGN KEY (subject_id) REFERENCES subjects(subject_id),
    FOREIGN KEY (paper_id) REFERENCES subject_papers(paper_id)
);

-- Permissions seed data (covering all models in the schema)
INSERT INTO permissions (name, description, is_global) VALUES
-- User & Roles (existing)
('manage_users', 'Create, update, deactivate users/teachers', FALSE),
('manage_roles', 'Create, update, assign roles to users', FALSE),
('manage_permissions', 'Assign/revoke permissions for roles', FALSE),

-- Students (existing)
('view_students', 'View student records', FALSE),
('manage_students', 'Add, update, delete student records', FALSE),
('import_students', 'Import students in bulk', FALSE),

-- Teachers (existing)
('view_teachers', 'View teacher records', FALSE),
('manage_teachers', 'Add, update, delete teacher records', FALSE),
('assign_subjects', 'Assign subjects to teachers', FALSE),

-- Classes (existing)
('view_classes', 'View classes/forms', FALSE),
('manage_classes', 'Add, update, delete classes/forms', FALSE),

-- Streams (existing)
('view_streams', 'View streams', FALSE),
('manage_streams', 'Add, update, delete streams', FALSE),

-- Subjects (existing)
('view_subjects', 'View subjects', FALSE),
('manage_subjects', 'Add, update, delete subjects', FALSE),

-- Exams (existing)
('view_exams', 'View exams', FALSE),
('manage_exams', 'Create, update, delete exams', FALSE),
('assign_exams', 'Assign exams to classes/teachers', FALSE),

-- Results (existing)
('view_results', 'View results', FALSE),
('enter_results', 'Enter or edit exam results', FALSE),
('approve_results', 'Approve/confirm results', FALSE),

-- Reports (existing)
('generate_reports', 'Generate academic reports and analytics', FALSE),

-- Settings (existing)
('manage_school', 'Update school profile and settings', FALSE),

-- New permissions for additional models
-- Schools (adding explicit permissions for completeness)
('view_schools', 'View school profiles', FALSE),

-- Subject Papers
('view_subject_papers', 'View subject papers', FALSE),
('manage_subject_papers', 'Add, update, delete subject papers', FALSE),

-- Role Permissions
('view_role_permissions', 'View role permissions assignments', FALSE),
('manage_role_permissions', 'Assign or revoke permissions to/from roles', FALSE),

-- Remarks Rules
('view_remarks_rules', 'View remarks rules for grading and comments', FALSE),
('manage_remarks_rules', 'Add, update, delete remarks rules', FALSE),
('apply_remarks', 'Apply remarks to results or aggregates', FALSE),

-- Exam Aggregates
('view_exam_aggregates', 'View exam aggregate reports and rankings', FALSE),
('manage_exam_aggregates', 'Calculate or update exam aggregates', FALSE),
('approve_exam_aggregates', 'Approve/confirm exam aggregates', FALSE),

-- Teacher Subjects
('view_teacher_subjects', 'View teacher subject assignments', FALSE),
('manage_teacher_subjects', 'Assign or remove subjects to/from teachers', FALSE),

-- Class Supervisors
('view_class_supervisors', 'View class supervisor assignments', FALSE),
('manage_class_supervisors', 'Assign or remove class supervisors', FALSE),

-- Class Teachers
('view_class_teachers', 'View class teacher assignments', FALSE),
('manage_class_teachers', 'Assign or remove class teachers for streams', FALSE);

-- Insert default grading system
INSERT INTO grading_systems (name, is_default, school_id) VALUES ('Default Grading', TRUE, NULL);

-- Insert default grading rules
INSERT INTO grading_rules (grading_system_id, grade, min_score, max_score, points, description) VALUES
((SELECT grading_system_id FROM grading_systems WHERE name = 'Default Grading'), 'A', 80, 100, 12, NULL),
((SELECT grading_system_id FROM grading_systems WHERE name = 'Default Grading'), 'A-', 75, 79, 11, NULL),
((SELECT grading_system_id FROM grading_systems WHERE name = 'Default Grading'), 'B+', 70, 74, 10, NULL),
((SELECT grading_system_id FROM grading_systems WHERE name = 'Default Grading'), 'B', 65, 69, 9, NULL),
((SELECT grading_system_id FROM grading_systems WHERE name = 'Default Grading'), 'B-', 60, 64, 8, NULL),
((SELECT grading_system_id FROM grading_systems WHERE name = 'Default Grading'), 'C+', 55, 59, 7, NULL),
((SELECT grading_system_id FROM grading_systems WHERE name = 'Default Grading'), 'C', 50, 54, 6, NULL),
((SELECT grading_system_id FROM grading_systems WHERE name = 'Default Grading'), 'C-', 45, 49, 5, NULL),
((SELECT grading_system_id FROM grading_systems WHERE name = 'Default Grading'), 'D+', 40, 44, 4, NULL),
((SELECT grading_system_id FROM grading_systems WHERE name = 'Default Grading'), 'D', 35, 39, 3, NULL),
((SELECT grading_system_id FROM grading_systems WHERE name = 'Default Grading'), 'D-', 30, 34, 2, NULL),
((SELECT grading_system_id FROM grading_systems WHERE name = 'Default Grading'), 'E', 0, 29, 1, NULL),
((SELECT grading_system_id FROM grading_systems WHERE name = 'Default Grading'), 'X', 0, 0, 0, 'Missing marks'),
((SELECT grading_system_id FROM grading_systems WHERE name = 'Default Grading'), 'Y', 0, 0, 0, 'Cheating');

-- Insert permission for managing grading systems
INSERT INTO permissions (name, description, is_global) VALUES
('manage_grading_systems', 'Create and manage grading systems', FALSE);

-- Assign manage_grading_systems permission to role_id 1 for school_id 1
INSERT INTO role_permissions (role_id, permission_id, school_id)
VALUES (1, (SELECT permission_id FROM permissions WHERE name = 'manage_grading_systems'), 1);

INSERT INTO permissions (name, description, is_global) VALUES
('manage_grading_systems', 'Create new grading systems', FALSE);
INSERT INTO role_permissions (role_id, permission_id, school_id)
VALUES (1, (SELECT permission_id FROM permissions WHERE name = 'manage_grading_systems'), 1);

-- ===============================
-- Class-Level Remarks
-- ===============================

-- Class Teacher
INSERT INTO remarks_rules (school_id, min_score, max_score, grade, remark_text, category, class_id, stream_id, subject_id, created_at) VALUES
(NULL, 80, 100, 'A', 'Excellent class performance, keep up the great work!', 'class_teacher', NULL, NULL, NULL, NOW()),
(NULL, 75, 79, 'A-', 'Very good results, consistent effort is showing.', 'class_teacher', NULL, NULL, NULL, NOW()),
(NULL, 70, 74, 'B+', 'Good performance, keep building on this progress.', 'class_teacher', NULL, NULL, NULL, NOW()),
(NULL, 65, 69, 'B', 'Steady progress, aim a little higher next time.', 'class_teacher', NULL, NULL, NULL, NOW()),
(NULL, 60, 64, 'B-', 'Fair results, with more focus you can improve.', 'class_teacher', NULL, NULL, NULL, NOW()),
(NULL, 55, 59, 'C+', 'Showing effort, keep practicing to do even better.', 'class_teacher', NULL, NULL, NULL, NOW()),
(NULL, 50, 54, 'C', 'Average performance, try to give a bit more effort.', 'class_teacher', NULL, NULL, NULL, NOW()),
(NULL, 45, 49, 'C-', 'Some struggles are visible, but steady effort will help.', 'class_teacher', NULL, NULL, NULL, NOW()),
(NULL, 40, 44, 'D+', 'Needs more focus, but you are capable of improving.', 'class_teacher', NULL, NULL, NULL, NOW()),
(NULL, 35, 39, 'D', 'Challenging results, keep practicing and don’t give up.', 'class_teacher', NULL, NULL, NULL, NOW()),
(NULL, 30, 34, 'D-', 'Difficulties noted, small consistent steps will help.', 'class_teacher', NULL, NULL, NULL, NOW()),
(NULL, 0, 29, 'E', 'Results are low, but with determination and support you can grow.', 'class_teacher', NULL, NULL, NULL, NOW());

-- ===============================
-- Stream-Level Remarks
-- ===============================

-- Stream Supervisor
INSERT INTO remarks_rules (school_id, min_score, max_score, grade, remark_text, category, class_id, stream_id, subject_id, created_at) VALUES
(NULL, 80, 100, 'A', 'The stream is performing excellently, keep the spirit alive!', 'stream_supervisor', NULL, NULL, NULL, NOW()),
(NULL, 75, 79, 'A-', 'Strong stream performance, very encouraging results.', 'stream_supervisor', NULL, NULL, NULL, NOW()),
(NULL, 70, 74, 'B+', 'Good progress as a stream, keep striving together.', 'stream_supervisor', NULL, NULL, NULL, NOW()),
(NULL, 65, 69, 'B', 'Solid effort, teamwork is showing positive results.', 'stream_supervisor', NULL, NULL, NULL, NOW()),
(NULL, 60, 64, 'B-', 'Average stream results, more unity and effort will help.', 'stream_supervisor', NULL, NULL, NULL, NOW()),
(NULL, 55, 59, 'C+', 'Stream performance is fair, steady focus will improve outcomes.', 'stream_supervisor', NULL, NULL, NULL, NOW()),
(NULL, 50, 54, 'C', 'The stream is trying, but consistency is needed.', 'stream_supervisor', NULL, NULL, NULL, NOW()),
(NULL, 45, 49, 'C-', 'Below average stream performance, keep working together.', 'stream_supervisor', NULL, NULL, NULL, NOW()),
(NULL, 40, 44, 'D+', 'Some struggles are noted, but effort can change the results.', 'stream_supervisor', NULL, NULL, NULL, NOW()),
(NULL, 35, 39, 'D', 'Challenging stream performance, more focus is required.', 'stream_supervisor', NULL, NULL, NULL, NOW()),
(NULL, 30, 34, 'D-', 'Weak outcomes, but unity and effort can lift the stream.', 'stream_supervisor', NULL, NULL, NULL, NOW()),
(NULL, 0, 29, 'E', 'Performance is low, but the stream can improve with dedication.', 'stream_supervisor', NULL, NULL, NULL, NOW());

-- ===============================
-- Student-Level Remarks
-- ===============================

-- Principal
INSERT INTO remarks_rules (school_id, min_score, max_score, grade, remark_text, category, class_id, stream_id, subject_id, created_at) VALUES
(NULL, 80, 100, 'A', 'Excellent results, you are a role model to others.', 'principal', NULL, NULL, NULL, NOW()),
(NULL, 75, 79, 'A-', 'Very good performance, keep shining.', 'principal', NULL, NULL, NULL, NOW()),
(NULL, 70, 74, 'B+', 'Good progress, we are proud of your effort.', 'principal', NULL, NULL, NULL, NOW()),
(NULL, 65, 69, 'B', 'Steady work, keep aiming higher.', 'principal', NULL, NULL, NULL, NOW()),
(NULL, 60, 64, 'B-', 'Fair results, more consistency will help.', 'principal', NULL, NULL, NULL, NOW()),
(NULL, 55, 59, 'C+', 'Some effort shown, but more focus is needed.', 'principal', NULL, NULL, NULL, NOW()),
(NULL, 50, 54, 'C', 'Average work, more effort needed.', 'principal', NULL, NULL, NULL, NOW()),
(NULL, 45, 49, 'C-', 'Below average, but capable of doing better.', 'principal', NULL, NULL, NULL, NOW()),
(NULL, 40, 44, 'D+', 'Results show struggles, keep pushing.', 'principal', NULL, NULL, NULL, NOW()),
(NULL, 35, 39, 'D', 'Facing challenges, but improvement is possible.', 'principal', NULL, NULL, NULL, NOW()),
(NULL, 30, 34, 'D-', 'Weak performance, consistent effort will help.', 'principal', NULL, NULL, NULL, NOW()),
(NULL, 0, 29, 'E', 'Results are low, but with guidance you can grow.', 'principal', NULL, NULL, NULL, NOW());

-- ===============================
-- Subject-Level Remarks
-- ===============================

-- Subject Teacher
INSERT INTO remarks_rules (school_id, min_score, max_score, grade, remark_text, category, class_id, stream_id, subject_id, created_at) VALUES
(NULL, 80, 100, 'A', 'Excellent grasp of the subject, keep excelling!', 'subject_teacher', NULL, NULL, NULL, NOW()),
(NULL, 75, 79, 'A-', 'Very good understanding, great job.', 'subject_teacher', NULL, NULL, NULL, NOW()),
(NULL, 70, 74, 'B+', 'Good performance, keep strengthening your skills.', 'subject_teacher', NULL, NULL, NULL, NOW()),
(NULL, 65, 69, 'B', 'Steady progress, aim for deeper understanding.', 'subject_teacher', NULL, NULL, NULL, NOW()),
(NULL, 60, 64, 'B-', 'Fair results, practice more for improvement.', 'subject_teacher', NULL, NULL, NULL, NOW()),
(NULL, 55, 59, 'C+', 'Some improvement shown, keep practicing.', 'subject_teacher', NULL, NULL, NULL, NOW()),
(NULL, 50, 54, 'C', 'Average performance, focus on weak areas.', 'subject_teacher', NULL, NULL, NULL, NOW()),
(NULL, 45, 49, 'C-', 'Some struggles, but effort will pay off.', 'subject_teacher', NULL, NULL, NULL, NOW()),
(NULL, 40, 44, 'D+', 'Needs more effort, keep practicing.', 'subject_teacher', NULL, NULL, NULL, NOW()),
(NULL, 35, 39, 'D', 'Facing difficulties, but progress is possible.', 'subject_teacher', NULL, NULL, NULL, NOW()),
(NULL, 30, 34, 'D-', 'Low results, keep working on basics.', 'subject_teacher', NULL, NULL, NULL, NOW()),
(NULL, 0, 29, 'E', 'Struggles in subject, but with effort you can grow.', 'subject_teacher', NULL, NULL, NULL, NOW());


CREATE TABLE school_settings (
    setting_id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    term_name VARCHAR(50) NOT NULL, -- e.g., Term 1, Term 2
    academic_year YEAR NOT NULL,
    closing_date DATE NULL,
    next_opening_date DATE NULL,
    next_term_fees DECIMAL(10,2) NULL,
    principal_name VARCHAR(150) NULL,
    principal_signature VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (school_id) REFERENCES schools(school_id) ON DELETE CASCADE,
    UNIQUE (school_id, term_name, academic_year)
);

CREATE TABLE student_fees (
    fee_id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    student_id INT NOT NULL,
    admission_no VARCHAR(50) NOT NULL,
    setting_id INT NOT NULL,
    fees_balance DECIMAL(10,2) NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (school_id) REFERENCES schools(school_id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (setting_id) REFERENCES school_settings(setting_id) ON DELETE CASCADE,
    UNIQUE (school_id, student_id, setting_id)
);
ALTER TABLE school_settings
ADD COLUMN principal_signature VARCHAR(255) NULL
AFTER principal_name;

CREATE TABLE exam_aggregates (
    aggregate_id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    exam_id INT NOT NULL,
    student_id INT NOT NULL,
    class_id INT NOT NULL,
    stream_id INT NOT NULL,
    term VARCHAR(50) NOT NULL,
    year YEAR NOT NULL,
    total_score DECIMAL(7,2) NOT NULL,
    mean_score DECIMAL(5,2) NOT NULL,
    mean_grade VARCHAR(5) NOT NULL,
    total_points DECIMAL(7,3) NOT NULL;
    position_class INT NULL,
    position_stream INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (school_id, exam_id, student_id),
    FOREIGN KEY (school_id) REFERENCES schools(school_id) ON DELETE CASCADE,
    FOREIGN KEY (exam_id) REFERENCES exams(exam_id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(class_id) ON DELETE CASCADE,
    FOREIGN KEY (stream_id) REFERENCES streams(stream_id) ON DELETE CASCADE
);
CREATE TABLE exam_subject_aggregates (
    subject_aggregate_id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    exam_id INT NOT NULL,
    student_id INT NOT NULL,
    subject_id INT NOT NULL,
    subject_score DECIMAL(7,2) NOT NULL,
    subject_grade VARCHAR(5) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (school_id, exam_id, student_id, subject_id),
    FOREIGN KEY (school_id) REFERENCES schools(school_id) ON DELETE CASCADE,
    FOREIGN KEY (exam_id) REFERENCES exams(exam_id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(subject_id) ON DELETE CASCADE
);
-- Add remark_text to exam_aggregates
ALTER TABLE exam_aggregates
ADD COLUMN remark_text VARCHAR(255) NULL
AFTER mean_grade;

-- Add remark_text to exam_subject_aggregates
ALTER TABLE exam_subject_aggregates
ADD COLUMN remark_text VARCHAR(255) NULL
AFTER subject_grade;

ALTER TABLE exam_aggregates
MODIFY COLUMN total_points DECIMAL(7,3) NOT NULL;

CREATE TABLE term_subject_aggregates(
    term_subject_aggregate_id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    student_id INT NOT NULL,
    subject_id INT NOT NULL,
    exam_id INT NOT NULL,
    class_id INT NOT NULL,
    stream_id INT NOT NULL,
    term VARCHAR(50) NOT NULL,
    year YEAR NOT NULL,
    subject_score DECIMAL(7,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (school_id, student_id, subject_id, exam_id, class_id, term, year),
    FOREIGN KEY (school_id) REFERENCES schools(school_id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(subject_id) ON DELETE CASCADE,
    FOREIGN KEY (exam_id) REFERENCES exams(exam_id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(class_id) ON DELETE CASCADE,
    FOREIGN KEY (stream_id) REFERENCES streams(stream_id) ON DELETE CASCADE
);




CREATE TABLE IF NOT EXISTS term_subject_totals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    student_id INT NOT NULL,
    class_id INT NOT NULL,
    stream_id INT NULL,
    term VARCHAR(50) NOT NULL,
    year YEAR NOT NULL,
    subject_id INT NOT NULL,
    subject_total DECIMAL(10,2) NOT NULL DEFAULT 0.00, -- sum of exam scores for this subject in the term
    subject_mean DECIMAL(7,2) NOT NULL DEFAULT 0.00,   -- avg of exam scores for this subject in the term
    exam_count INT NOT NULL DEFAULT 0,                 -- how many exams contributed
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_subject_term (school_id, student_id, class_id, subject_id, term, year),
    FOREIGN KEY (subject_id) REFERENCES subjects(subject_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


ALTER TABLE term_subject_totals
ADD COLUMN subject_grade VARCHAR(5) NULL AFTER subject_mean,
ADD COLUMN subject_teacher_remark_text VARCHAR(255) NULL AFTER subject_grade,
ADD COLUMN grading_system_id INT NULL AFTER exam_count;
ALTER TABLE term_subject_totals
ADD COLUMN subject_teacher_id INT NULL AFTER grading_system_id,
ADD FOREIGN KEY (subject_teacher_id) REFERENCES users(user_id) ON DELETE SET NULL;
-- Step 1: Alter term_subject_totals to add subject_points
ALTER TABLE term_subject_totals
ADD COLUMN subject_points DECIMAL(7,3) NULL AFTER subject_teacher_remark_text;

-- New table: student_termly_historical_data
CREATE TABLE student_termly_historical_data (
    historical_id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    student_id INT NOT NULL,
    class_id INT NOT NULL,
    stream_id INT NULL,
    term VARCHAR(50) NOT NULL,
    year YEAR NOT NULL,
    total_marks DECIMAL(10,2) NOT NULL DEFAULT 0.00,  -- Sum of all subject_mean for the student in the term
    average DECIMAL(7,2) NOT NULL DEFAULT 0.00,       -- total_marks / min_subjects (student's overall mean score)
    total_points DECIMAL(7,3) NOT NULL DEFAULT 0.000, -- (Sum of all subject_points) / min_subjects (student's overall mean points)
    class_position INT NULL,                          -- Rank in class based on total_points DESC
    stream_position INT NULL,                         -- Rank in stream based on total_points DESC
    grading_system_id INT NULL,                       -- Inherited from exams/grading
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_historical_term (school_id, student_id, term, year),
    FOREIGN KEY (school_id) REFERENCES schools(school_id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(class_id) ON DELETE CASCADE,
    FOREIGN KEY (grading_system_id) REFERENCES grading_systems(grading_system_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- Updated table: student_term_results_aggregates
CREATE TABLE student_term_results_aggregates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    student_id INT NOT NULL,
    class_id INT NOT NULL,
    stream_id INT NULL,  -- Matches term_subject_totals (can be NULL if not stream-specific)
    term VARCHAR(50) NOT NULL,
    year YEAR NOT NULL,
    total_marks DECIMAL(10,2) NOT NULL DEFAULT 0.00,  -- Sum of all subject_mean for the student in the term
    average DECIMAL(7,2) NOT NULL DEFAULT 0.00,       -- total_marks / min_subjects (student's overall mean score)
    total_points DECIMAL(7,3) NOT NULL DEFAULT 0.000, -- (Sum of all subject_points) / min_subjects (student's overall mean points)
    min_subjects INT NOT NULL DEFAULT 0,              -- The consistent min_subjects value from exams for the class/term
    grade VARCHAR(5) NULL,                            -- Overall student grade based on FLOOR(average)
    class_position INT NULL,                          -- Rank in class based on total_points DESC
    stream_position INT NULL,                         -- Rank in stream based on total_points DESC
    class_teacher_remark_text VARCHAR(255) NULL,      -- Remark from class_teacher based on grade
    principal_remark_text VARCHAR(255) NULL,          -- Remark from principal based on grade
    kcpe_score INT NULL,                              -- KCPE score from students table
    kcpe_grade VARCHAR(5) NULL,                       -- KCPE grade from students table
    grading_system_id INT NULL,                       -- Inherited from term_subject_totals
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_student_term (school_id, student_id, class_id, term, year),
    FOREIGN KEY (school_id) REFERENCES schools(school_id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(class_id) ON DELETE CASCADE,
    FOREIGN KEY (grading_system_id) REFERENCES grading_systems(grading_system_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE student_termly_historical_data
ADD COLUMN min_subjects INT NOT NULL DEFAULT 0 AFTER total_points;

-- ALTER TABLE statement to add the new columns
ALTER TABLE student_term_results_aggregates
ADD COLUMN class_total_students INT NULL AFTER stream_position,
ADD COLUMN stream_total_students INT NULL AFTER class_total_students;


-- Drop the table if it exists (to clean up any partial creation)
DROP TABLE IF EXISTS messages_sent;

-- Create messages_sent table to log sent SMS messages

CREATE TABLE messages_sent (
    message_id          INT AUTO_INCREMENT PRIMARY KEY,
    school_id           INT NOT NULL,        -- Required: links to school
    sent_by             INT NULL,            -- Nullable: user_id of sender (allows SET NULL on delete)
    student_id          INT NULL,            -- Optional: specific student reference
    class_id            INT NULL,            -- Optional: class context (e.g., class-wide send)
    stream_id           INT NULL,            -- Optional: stream context (e.g., stream-wide send)
    recipient_type      ENUM('parent', 'teacher') NOT NULL,
    recipient_phone     VARCHAR(20) NOT NULL,
    message_content     TEXT NOT NULL,
    message_type        ENUM('exam_results', 'term_results', 'general') NOT NULL,
    reference_id        INT NULL,            -- e.g., exam_id or term setting_id
    reference_type      VARCHAR(50) NULL,    -- e.g., 'exam', 'term', 'student'
    status              ENUM('queued', 'sent', 'failed', 'delivered', 'undelivered') DEFAULT 'queued' NOT NULL,
    sms_cost            DECIMAL(6,2) DEFAULT 0.00,
    error_message       TEXT NULL,
    sent_at             TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Foreign Keys (all compatible with your schema)
    FOREIGN KEY (school_id) REFERENCES schools(school_id) ON DELETE CASCADE,
    FOREIGN KEY (sent_by) REFERENCES users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE SET NULL,
    FOREIGN KEY (class_id) REFERENCES classes(class_id) ON DELETE SET NULL,
    FOREIGN KEY (stream_id) REFERENCES streams(stream_id) ON DELETE SET NULL,
    
    -- Indexes for performance (query by school, student, class, stream, etc.)
    INDEX idx_school_sent_at (school_id, sent_at),
    INDEX idx_sent_by (sent_by),
    INDEX idx_student (student_id),
    INDEX idx_class (class_id),
    INDEX idx_stream (stream_id),
    INDEX idx_recipient_phone (recipient_phone),
    INDEX idx_status (status),
    INDEX idx_message_type (message_type),
    
    -- Unique constraint to prevent exact duplicates (includes student_id for precision)
    UNIQUE KEY unique_message (school_id, student_id, recipient_phone, message_content(255), sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE messages_sent ADD COLUMN teacher_id INT NULL;



-- 18. Time Slots (global or per school; defines daily structure with breaks)
CREATE TABLE time_slots (
    slot_id         INT AUTO_INCREMENT PRIMARY KEY,
    school_id       INT NOT NULL,  -- Allow school-specific schedules
    slot_number     INT NOT NULL,  -- e.g., 1 to 10
    start_time      TIME NOT NULL, -- e.g., '08:00:00'
    end_time        TIME NOT NULL, -- e.g., '08:40:00'
    is_break        TINYINT(1) DEFAULT 0,  -- 1 for break/lunch
    break_name      VARCHAR(50) NULL,  -- e.g., 'BREAK', 'LUNCH'
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (school_id) REFERENCES schools(school_id) ON DELETE CASCADE,
    UNIQUE (school_id, slot_number)  -- One sequence per school
);

-- 19. Timetable Entries (assigns subject/teacher to class/stream/day/slot)
CREATE TABLE timetable_entries (
    entry_id        INT AUTO_INCREMENT PRIMARY KEY,
    school_id       INT NOT NULL,
    class_id        INT NOT NULL,  -- e.g., Form 1
    stream_id       INT NULL,      -- Optional: per stream; NULL for class-wide
    day_of_week     ENUM('Mo', 'Tu', 'We', 'Th', 'Fr') NOT NULL,  -- Days from examples
    slot_id         INT NOT NULL,
    subject_id      INT NULL,      -- NULL for breaks (but breaks are in time_slots)
    teacher_id      INT NULL,      -- From users (teachers); NULL for non-lesson slots
    group_type      VARCHAR(50) NULL,  -- e.g., 'Group 1', 'Group 2' for electives in Forms 3-4
    notes           TEXT NULL,     -- e.g., 'Double lesson'
    academic_year   YEAR NOT NULL, -- To version timetables by year
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (school_id) REFERENCES schools(school_id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(class_id) ON DELETE CASCADE,
    FOREIGN KEY (stream_id) REFERENCES streams(stream_id) ON DELETE CASCADE,
    FOREIGN KEY (slot_id) REFERENCES time_slots(slot_id) ON DELETE RESTRICT,
    FOREIGN KEY (subject_id) REFERENCES subjects(subject_id) ON DELETE SET NULL,
    FOREIGN KEY (teacher_id) REFERENCES users(user_id) ON DELETE SET NULL,
    UNIQUE (school_id, class_id, stream_id, day_of_week, slot_id, academic_year)  -- No overlaps
);

-- 20. Subject Frequencies (defines how many lessons per week per subject/class)
CREATE TABLE subject_frequencies (
    frequency_id    INT AUTO_INCREMENT PRIMARY KEY,
    school_id       INT NOT NULL,
    class_id        INT NOT NULL,
    subject_id      INT NOT NULL,
    lessons_per_week INT NOT NULL DEFAULT 4,  -- e.g., MAT might have 5-6, others 3-4
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (school_id) REFERENCES schools(school_id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(class_id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(subject_id) ON DELETE CASCADE,
    UNIQUE (school_id, class_id, subject_id)
);