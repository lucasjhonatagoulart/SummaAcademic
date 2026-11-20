-- Plataforma acadêmica (PHP + MySQL) — estrutura do banco
-- Criado pelo instalador (install/). Charset utf8mb4.

CREATE TABLE settings (
  k VARCHAR(64) NOT NULL PRIMARY KEY,
  v TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE institutions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL,
  short_name VARCHAR(40) NOT NULL,
  legal_name VARCHAR(200),
  cnpj VARCHAR(20),
  emec_code VARCHAR(20),
  address VARCHAR(255),
  city VARCHAR(120),
  state VARCHAR(2),
  zip_code VARCHAR(10),
  phone VARCHAR(30),
  email VARCHAR(160),
  website VARCHAR(160),
  director_name VARCHAR(160),
  teaching_director_name VARCHAR(160),
  secretary_name VARCHAR(160),
  logo_path VARCHAR(255),
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE roles (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(20) NOT NULL,
  name VARCHAR(80) NOT NULL,
  description VARCHAR(255)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE UNIQUE INDEX roles_kind ON roles (kind);

CREATE TABLE role_permissions (
  role_id INT UNSIGNED NOT NULL,
  perm VARCHAR(64) NOT NULL,
  PRIMARY KEY (role_id, perm),
  FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  institution_id INT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  email VARCHAR(160) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'ACTIVE',
  blocked_reason VARCHAR(255),
  must_change_password TINYINT NOT NULL DEFAULT 0,
  password_expires_at DATETIME,
  failed_logins INT NOT NULL DEFAULT 0,
  locked_until DATETIME,
  last_login_at DATETIME,
  avatar_path VARCHAR(255),
  notif_seen TEXT,
  phone VARCHAR(30),
  created_at DATETIME NOT NULL,
  FOREIGN KEY (institution_id) REFERENCES institutions (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE UNIQUE INDEX users_email ON users (email);

CREATE TABLE user_roles (
  user_id INT UNSIGNED NOT NULL,
  role_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, role_id),
  FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Instituições extras que o usuário acessa (a de vínculo fica em users.institution_id)
CREATE TABLE user_institutions (
  user_id INT UNSIGNED NOT NULL,
  institution_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, institution_id),
  FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE user_permissions (
  user_id INT UNSIGNED NOT NULL,
  perm VARCHAR(64) NOT NULL,
  effect VARCHAR(5) NOT NULL,
  PRIMARY KEY (user_id, perm),
  FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE audit_logs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  institution_id INT UNSIGNED,
  actor_id INT UNSIGNED,
  action VARCHAR(60) NOT NULL,
  entity VARCHAR(40),
  entity_id INT UNSIGNED,
  summary VARCHAR(500),
  reason VARCHAR(255),
  ip VARCHAR(45),
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX audit_inst_date ON audit_logs (institution_id, created_at);

CREATE TABLE announcements (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  institution_id INT UNSIGNED NOT NULL,
  kind VARCHAR(5) NOT NULL,
  category VARCHAR(20),
  title VARCHAR(120) NOT NULL,
  body TEXT NOT NULL,
  audience VARCHAR(12) NOT NULL DEFAULT 'ALL',
  author_label VARCHAR(80) NOT NULL,
  pinned TINYINT NOT NULL DEFAULT 0,
  image_path VARCHAR(255),
  published_on DATE NOT NULL,
  expires_on DATE,
  created_by INT UNSIGNED,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX ann_inst ON announcements (institution_id, kind, published_on);

CREATE TABLE courses (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  institution_id INT UNSIGNED NOT NULL,
  code VARCHAR(12) NOT NULL,
  name VARCHAR(160) NOT NULL,
  degree VARCHAR(40) NOT NULL DEFAULT 'Bacharelado',
  periods INT NOT NULL DEFAULT 8,
  active TINYINT NOT NULL DEFAULT 1,
  FOREIGN KEY (institution_id) REFERENCES institutions (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE subjects (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  institution_id INT UNSIGNED NOT NULL,
  code VARCHAR(12) NOT NULL,
  name VARCHAR(160) NOT NULL,
  workload INT NOT NULL,
  modality VARCHAR(1) NOT NULL DEFAULT 'P',
  tracks_attendance TINYINT NOT NULL DEFAULT 1,
  FOREIGN KEY (institution_id) REFERENCES institutions (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE UNIQUE INDEX subjects_code ON subjects (institution_id, code);

CREATE TABLE course_subjects (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  course_id INT UNSIGNED NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  period INT NOT NULL,
  required TINYINT NOT NULL DEFAULT 1,
  prerequisites VARCHAR(255),
  FOREIGN KEY (course_id) REFERENCES courses (id) ON DELETE CASCADE,
  FOREIGN KEY (subject_id) REFERENCES subjects (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE UNIQUE INDEX cs_unique ON course_subjects (course_id, subject_id);

CREATE TABLE terms (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  institution_id INT UNSIGNED NOT NULL,
  name VARCHAR(20) NOT NULL,
  starts_on DATE NOT NULL,
  ends_on DATE NOT NULL,
  active TINYINT NOT NULL DEFAULT 0,
  closed TINYINT NOT NULL DEFAULT 0,
  elective_start DATE,
  elective_end DATE,
  max_electives INT NOT NULL DEFAULT 2,
  grades_deadline DATE,
  FOREIGN KEY (institution_id) REFERENCES institutions (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE class_groups (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  institution_id INT UNSIGNED NOT NULL,
  term_id INT UNSIGNED NOT NULL,
  course_id INT UNSIGNED NOT NULL,
  code VARCHAR(20) NOT NULL,
  period_number INT NOT NULL,
  shift VARCHAR(10) NOT NULL DEFAULT 'Noite',
  FOREIGN KEY (term_id) REFERENCES terms (id),
  FOREIGN KEY (course_id) REFERENCES courses (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE professors (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  registration VARCHAR(20) NOT NULL,
  title VARCHAR(40),
  department VARCHAR(80),
  hired_on DATE,
  FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE students (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  ra VARCHAR(20) NOT NULL,
  course_id INT UNSIGNED NOT NULL,
  group_id INT UNSIGNED,
  entry_term VARCHAR(20),
  status VARCHAR(12) NOT NULL DEFAULT 'ATIVO',
  cpf VARCHAR(14),
  birth_date DATE,
  address VARCHAR(255),
  guardian VARCHAR(160),
  monthly_fee_cents INT NOT NULL DEFAULT 0,
  FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  FOREIGN KEY (course_id) REFERENCES courses (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE UNIQUE INDEX students_ra ON students (ra);

CREATE TABLE sections (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  institution_id INT UNSIGNED NOT NULL,
  term_id INT UNSIGNED NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  code VARCHAR(30) NOT NULL,
  offer_type VARCHAR(10) NOT NULL DEFAULT 'REGULAR',
  room VARCHAR(60),
  capacity INT,
  min_grade DECIMAL(4,2) NOT NULL DEFAULT 6.00,
  min_attendance INT NOT NULL DEFAULT 75,
  FOREIGN KEY (term_id) REFERENCES terms (id),
  FOREIGN KEY (subject_id) REFERENCES subjects (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE section_groups (
  section_id INT UNSIGNED NOT NULL,
  group_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (section_id, group_id),
  FOREIGN KEY (section_id) REFERENCES sections (id) ON DELETE CASCADE,
  FOREIGN KEY (group_id) REFERENCES class_groups (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE section_professors (
  section_id INT UNSIGNED NOT NULL,
  professor_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (section_id, professor_id),
  FOREIGN KEY (section_id) REFERENCES sections (id) ON DELETE CASCADE,
  FOREIGN KEY (professor_id) REFERENCES professors (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE schedules (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  section_id INT UNSIGNED NOT NULL,
  weekday INT NOT NULL,
  start_time VARCHAR(5) NOT NULL,
  end_time VARCHAR(5) NOT NULL,
  FOREIGN KEY (section_id) REFERENCES sections (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE enrollments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  student_id INT UNSIGNED NOT NULL,
  section_id INT UNSIGNED NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'CURSANDO',
  final_grade DECIMAL(4,2),
  attendance_pct DECIMAL(5,2),
  created_at DATETIME NOT NULL,
  FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE,
  FOREIGN KEY (section_id) REFERENCES sections (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE UNIQUE INDEX enr_unique ON enrollments (student_id, section_id);

CREATE TABLE lessons (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  section_id INT UNSIGNED NOT NULL,
  lesson_date DATE NOT NULL,
  topic VARCHAR(255) NOT NULL,
  hours INT NOT NULL DEFAULT 2,
  created_by INT UNSIGNED,
  FOREIGN KEY (section_id) REFERENCES sections (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE attendance (
  lesson_id INT UNSIGNED NOT NULL,
  enrollment_id INT UNSIGNED NOT NULL,
  present TINYINT NOT NULL DEFAULT 1,
  PRIMARY KEY (lesson_id, enrollment_id),
  FOREIGN KEY (lesson_id) REFERENCES lessons (id) ON DELETE CASCADE,
  FOREIGN KEY (enrollment_id) REFERENCES enrollments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE assessments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  section_id INT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL,
  weight DECIMAL(5,2) NOT NULL DEFAULT 1,
  due_on DATE,
  published TINYINT NOT NULL DEFAULT 0,
  FOREIGN KEY (section_id) REFERENCES sections (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE grades (
  assessment_id INT UNSIGNED NOT NULL,
  enrollment_id INT UNSIGNED NOT NULL,
  score DECIMAL(4,2) NOT NULL,
  PRIMARY KEY (assessment_id, enrollment_id),
  FOREIGN KEY (assessment_id) REFERENCES assessments (id) ON DELETE CASCADE,
  FOREIGN KEY (enrollment_id) REFERENCES enrollments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE transcripts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  institution_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  code VARCHAR(20) NOT NULL,
  snapshot MEDIUMTEXT NOT NULL,
  sha256 VARCHAR(64) NOT NULL,
  issued_by INT UNSIGNED,
  issued_at DATETIME NOT NULL,
  FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE UNIQUE INDEX transcripts_code ON transcripts (code);

CREATE TABLE professor_rates (
  professor_id INT UNSIGNED NOT NULL,
  institution_id INT UNSIGNED NOT NULL,
  rate_cents INT NOT NULL,
  unit VARCHAR(5) NOT NULL DEFAULT 'AULA',
  updated_by INT UNSIGNED,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (professor_id, institution_id),
  FOREIGN KEY (professor_id) REFERENCES professors (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE professor_payments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  institution_id INT UNSIGNED NOT NULL,
  professor_id INT UNSIGNED NOT NULL,
  period_from DATE NOT NULL,
  period_to DATE NOT NULL,
  lessons INT NOT NULL,
  hours INT NOT NULL,
  rate_cents INT NOT NULL,
  unit VARCHAR(5) NOT NULL,
  gross_cents INT NOT NULL,
  adjust_cents INT NOT NULL DEFAULT 0,
  adjust_reason VARCHAR(160),
  amount_cents INT NOT NULL,
  paid_on DATE NOT NULL,
  method VARCHAR(20) NOT NULL,
  notes VARCHAR(255),
  created_by INT UNSIGNED,
  created_at DATETIME NOT NULL,
  updated_by INT UNSIGNED,
  updated_at DATETIME,
  FOREIGN KEY (professor_id) REFERENCES professors (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX prof_pay_inst ON professor_payments (institution_id, professor_id, paid_on);

CREATE TABLE professor_payment_lessons (
  payment_id INT UNSIGNED NOT NULL,
  lesson_id INT UNSIGNED NOT NULL,
  amount_cents INT NOT NULL,
  PRIMARY KEY (payment_id, lesson_id),
  FOREIGN KEY (payment_id) REFERENCES professor_payments (id) ON DELETE CASCADE,
  FOREIGN KEY (lesson_id) REFERENCES lessons (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE charges (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  institution_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  description VARCHAR(160) NOT NULL,
  competence VARCHAR(7) NOT NULL,
  due_on DATE NOT NULL,
  amount_cents INT NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'ABERTA',
  paid_on DATE,
  paid_cents INT,
  method VARCHAR(20),
  created_at DATETIME NOT NULL,
  FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX charges_student ON charges (student_id, competence);
CREATE INDEX charges_due ON charges (institution_id, status, due_on);

-- Compras: solicitações, itens, cotações (até 3), preços por item e histórico
CREATE TABLE purchase_requests (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  institution_id INT UNSIGNED NOT NULL,
  requester_id INT UNSIGNED NOT NULL,
  title VARCHAR(160) NOT NULL,
  sector VARCHAR(80) NOT NULL,
  justification TEXT NOT NULL,
  needed_by DATE,
  priority VARCHAR(10) NOT NULL DEFAULT 'NORMAL',
  place VARCHAR(60) NOT NULL,
  place_other VARCHAR(120),
  link VARCHAR(500),
  payment VARCHAR(10) NOT NULL,
  installments INT NOT NULL DEFAULT 1,
  status VARCHAR(12) NOT NULL DEFAULT 'RASCUNHO',
  winner_quote_id INT UNSIGNED,
  approved_cents INT,
  decided_by INT UNSIGNED,
  decided_at DATETIME,
  decision_note VARCHAR(500),
  purchase_ref VARCHAR(60),
  purchased_on DATE,
  invoice_number VARCHAR(60),
  received_on DATE,
  received_by INT UNSIGNED,
  patrimony_status VARCHAR(10) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (requester_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX purchase_req_inst ON purchase_requests (institution_id, status);

CREATE TABLE purchase_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  request_id INT UNSIGNED NOT NULL,
  position INT NOT NULL,
  description VARCHAR(255) NOT NULL,
  quantity INT NOT NULL,
  unit VARCHAR(10) NOT NULL DEFAULT 'un.',
  est_unit_cents INT,
  patrimonial TINYINT NOT NULL DEFAULT 0,
  received_qty INT,
  FOREIGN KEY (request_id) REFERENCES purchase_requests (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE purchase_quotes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  request_id INT UNSIGNED NOT NULL,
  supplier VARCHAR(160) NOT NULL,
  place VARCHAR(60) NOT NULL,
  link VARCHAR(500),
  payment VARCHAR(10) NOT NULL,
  installments INT NOT NULL DEFAULT 1,
  delivery_days INT,
  shipping_cents INT NOT NULL DEFAULT 0,
  total_cents INT NOT NULL,
  notes VARCHAR(255),
  created_by INT UNSIGNED,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (request_id) REFERENCES purchase_requests (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE purchase_quote_prices (
  quote_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  unit_cents INT NOT NULL,
  PRIMARY KEY (quote_id, item_id),
  FOREIGN KEY (quote_id) REFERENCES purchase_quotes (id) ON DELETE CASCADE,
  FOREIGN KEY (item_id) REFERENCES purchase_items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE purchase_events (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  request_id INT UNSIGNED NOT NULL,
  actor_id INT UNSIGNED,
  action VARCHAR(30) NOT NULL,
  note VARCHAR(500),
  from_status VARCHAR(12),
  reverted TINYINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (request_id) REFERENCES purchase_requests (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX purchase_events_req ON purchase_events (request_id, created_at);
