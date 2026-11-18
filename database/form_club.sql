-- FORM Club v1. Import into a NEW, selected schema; this file contains no cleanup.
SET NAMES utf8mb4;
SET SESSION time_zone = '+08:00';
SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

CREATE TABLE users (
  user_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(254) NOT NULL,
  password_hash VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  full_name VARCHAR(120) NOT NULL,
  phone VARCHAR(32) NULL,
  role ENUM('MEMBER','ADMIN') NOT NULL DEFAULT 'MEMBER',
  status ENUM('ACTIVE','DISABLED') NOT NULL DEFAULT 'ACTIVE',
  UNIQUE KEY uq_user_email (email),
  KEY ix_member_name (role, full_name, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE membership_plans (
  plan_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1 CHECK (version > 0),
  price DECIMAL(10,2) NOT NULL CHECK (price > 0),
  duration_days INT UNSIGNED NOT NULL CHECK (duration_days > 0),
  status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  UNIQUE KEY uq_plan_version (name, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE orders (
  order_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  plan_id INT UNSIGNED NOT NULL,
  method ENUM('SIMULATED','OFFLINE') NOT NULL,
  request_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status ENUM('PENDING','PAID','CANCELLED') NOT NULL DEFAULT 'PENDING',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_order_request (user_id, request_key),
  KEY ix_order_owner (user_id, status, order_id),
  KEY ix_order_status_time (status, created_at, order_id),
  CONSTRAINT fk_order_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE RESTRICT,
  CONSTRAINT fk_order_plan FOREIGN KEY (plan_id) REFERENCES membership_plans(plan_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payments (
  payment_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  status ENUM('PENDING','SUCCEEDED','FAILED') NOT NULL,
  paid_at DATETIME NULL,
  attempt_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  success_marker TINYINT GENERATED ALWAYS AS
    (CASE WHEN status = 'SUCCEEDED' THEN 1 ELSE NULL END) VIRTUAL,
  CHECK ((status = 'SUCCEEDED' AND paid_at IS NOT NULL)
      OR (status <> 'SUCCEEDED' AND paid_at IS NULL)),
  UNIQUE KEY uq_payment_request (order_id, attempt_key),
  UNIQUE KEY uq_one_settlement (order_id, success_marker),
  KEY ix_receipts (status, paid_at),
  CONSTRAINT fk_payment_order FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE memberships (
  membership_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  UNIQUE KEY uq_membership_order (order_id),
  CHECK (ends_at > starts_at),
  CONSTRAINT fk_period_order FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE coaches (
  coach_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  specialty VARCHAR(255) NOT NULL DEFAULT '',
  status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE venues (
  venue_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  location VARCHAR(255) NOT NULL,
  capacity INT UNSIGNED NOT NULL CHECK (capacity > 0),
  status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  UNIQUE KEY uq_venue_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE courses (
  course_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  category VARCHAR(80) NOT NULL DEFAULT 'General',
  description TEXT NOT NULL,
  duration_minutes INT UNSIGNED NOT NULL CHECK (duration_minutes > 0),
  status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE class_sessions (
  session_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  course_id INT UNSIGNED NOT NULL,
  coach_id INT UNSIGNED NOT NULL,
  venue_id INT UNSIGNED NOT NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  capacity INT UNSIGNED NOT NULL CHECK (capacity > 0),
  status ENUM('SCHEDULED','CANCELLED','COMPLETED') NOT NULL DEFAULT 'SCHEDULED',
  CHECK (ends_at > starts_at),
  KEY ix_schedule (status, starts_at, session_id),
  KEY ix_coach_time (coach_id, status, starts_at),
  KEY ix_venue_time (venue_id, status, starts_at),
  CONSTRAINT fk_session_course FOREIGN KEY (course_id) REFERENCES courses(course_id) ON DELETE RESTRICT,
  CONSTRAINT fk_session_coach FOREIGN KEY (coach_id) REFERENCES coaches(coach_id) ON DELETE RESTRICT,
  CONSTRAINT fk_session_venue FOREIGN KEY (venue_id) REFERENCES venues(venue_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bookings (
  booking_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  session_id INT UNSIGNED NOT NULL,
  status ENUM('CONFIRMED','CANCELLED_BY_MEMBER','CANCELLED_BY_CENTER') NOT NULL DEFAULT 'CONFIRMED',
  booked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  cancelled_at DATETIME NULL,
  CHECK ((status = 'CONFIRMED' AND cancelled_at IS NULL)
      OR (status <> 'CONFIRMED' AND cancelled_at IS NOT NULL)),
  UNIQUE KEY uq_member_session (user_id, session_id),
  KEY ix_roster (session_id, status, booking_id),
  CONSTRAINT fk_booking_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE RESTRICT,
  CONSTRAINT fk_booking_session FOREIGN KEY (session_id) REFERENCES class_sessions(session_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attendance (
  attendance_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  booking_id INT UNSIGNED NOT NULL,
  status ENUM('PRESENT','ABSENT') NOT NULL,
  checked_at DATETIME NULL,
  CHECK ((status = 'PRESENT' AND checked_at IS NOT NULL)
      OR (status = 'ABSENT' AND checked_at IS NULL)),
  UNIQUE KEY uq_attendance_booking (booking_id),
  CONSTRAINT fk_result_booking FOREIGN KEY (booking_id) REFERENCES bookings(booking_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Locking read sees a committed order even under repeatable-read snapshots.
DELIMITER //
CREATE TRIGGER protect_plan_version BEFORE UPDATE ON membership_plans FOR EACH ROW
BEGIN
  IF (NEW.price <> OLD.price OR NEW.duration_days <> OLD.duration_days
      OR BINARY NEW.name <> BINARY OLD.name OR NEW.version <> OLD.version)
     AND EXISTS (SELECT 1 FROM orders WHERE plan_id = OLD.plan_id LIMIT 1 FOR UPDATE) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referenced plan version is immutable';
  END IF;
END//
CREATE TRIGGER protect_order_terms BEFORE UPDATE ON orders FOR EACH ROW
BEGIN
  IF NEW.user_id <> OLD.user_id OR NEW.plan_id <> OLD.plan_id OR NEW.method <> OLD.method
     OR NEW.request_key <> OLD.request_key OR NEW.created_at <> OLD.created_at
     OR (OLD.status IN ('PAID','CANCELLED') AND NEW.status <> OLD.status) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order terms and terminal state are immutable';
  END IF;
END//
CREATE TRIGGER protect_payment_result BEFORE UPDATE ON payments FOR EACH ROW
BEGIN
  IF NEW.order_id <> OLD.order_id OR NEW.attempt_key <> OLD.attempt_key
     OR NEW.attempted_at <> OLD.attempted_at
     OR (OLD.status <> 'PENDING' AND
          (NEW.status <> OLD.status OR NOT (NEW.paid_at <=> OLD.paid_at))) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Recorded payment attempt is immutable';
  END IF;
END//
DELIMITER ;

-- All dates are relative to import day (Shanghai). Demo-only password FormClub2026!.
START TRANSACTION;
SET @demo_hash = '$2y$10$4LQLXrktBtLso6haRem4J.iN8dq2by3N6rjXLK0WZKTmx9PWrGPze';
SET @today = CURRENT_DATE();
INSERT INTO users (user_id,email,password_hash,full_name,role) VALUES
  (1,'admin@form.test',@demo_hash,'Club Manager','ADMIN'),
  (2,'member@form.test',@demo_hash,'Jamie Lin','MEMBER'),
  (3,'new@form.test',@demo_hash,'Sam Yu','MEMBER');
INSERT INTO membership_plans (plan_id,name,version,price,duration_days) VALUES
  (1,'A fresh start',1,299.00,30),(2,'Build your rhythm',1,799.00,90),(3,'Make it a lifestyle',1,2699.00,365);
INSERT INTO orders (order_id,user_id,plan_id,method,request_key,status,created_at) VALUES
  (1,2,1,'SIMULATED','demo-first','PAID',@today - INTERVAL 5 DAY),
  (2,3,2,'OFFLINE','demo-offline','PENDING',@today);
INSERT INTO payments (order_id,status,paid_at,attempt_key,attempted_at) VALUES
  (1,'SUCCEEDED',@today - INTERVAL 5 DAY,'demo-receipt',@today - INTERVAL 5 DAY);
INSERT INTO memberships (order_id,starts_at,ends_at) VALUES
  (1,@today - INTERVAL 5 DAY,@today + INTERVAL 25 DAY);
INSERT INTO coaches (coach_id,name,specialty) VALUES
  (1,'Maya Chen','Yoga'),(2,'Alex Wu','Strength'),(3,'Nina Liu','Pilates');
INSERT INTO venues (venue_id,name,location,capacity) VALUES
  (1,'Studio A','Level 1',24),(2,'Training Floor','Level 1',32),(3,'Studio B','Level 2',20);
INSERT INTO courses (course_id,name,category,description,duration_minutes) VALUES
  (1,'Power Flow','Yoga','A guided yoga flow for balance and mobility.',60),
  (2,'Strength Foundations','Strength','Build strength with guided full-body exercises.',60),
  (3,'Core & Control','Pilates','Mat practice for controlled movement.',45);
INSERT INTO class_sessions (session_id,course_id,coach_id,venue_id,starts_at,ends_at,capacity,status) VALUES
  (1,1,1,1,@today + INTERVAL 1 DAY + INTERVAL 18 HOUR,@today + INTERVAL 1 DAY + INTERVAL 19 HOUR,12,'SCHEDULED'),
  (2,2,2,2,@today + INTERVAL 2 DAY + INTERVAL 12 HOUR,@today + INTERVAL 2 DAY + INTERVAL 13 HOUR,16,'SCHEDULED'),
  (3,3,3,3,@today - INTERVAL 1 DAY + INTERVAL 9 HOUR,@today - INTERVAL 1 DAY + INTERVAL 585 MINUTE,12,'COMPLETED');
INSERT INTO bookings (booking_id,user_id,session_id,booked_at) VALUES
  (1,2,3,@today - INTERVAL 2 DAY);
INSERT INTO attendance (booking_id,status,checked_at) VALUES
  (1,'PRESENT',@today - INTERVAL 1 DAY + INTERVAL 535 MINUTE);
COMMIT;
