-- Run ONCE against a fresh database initialized by form_club.sql.
-- All generated operational dates are relative to a single Shanghai import day.
SET SESSION time_zone = '+08:00';
SET NAMES utf8mb4;
DELIMITER //
CREATE PROCEDURE load_form_club_scale()
BEGIN
  DECLARE day_zero DATE DEFAULT CURRENT_DATE();
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    RESIGNAL;
  END;
  IF (SELECT COUNT(*) FROM users) <> 3 OR (SELECT COUNT(*) FROM class_sessions) <> 3
     OR (SELECT COUNT(*) FROM membership_plans WHERE plan_id=3 AND duration_days=365) <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Start with a fresh form_club.sql fixture';
  END IF;
  CREATE TEMPORARY TABLE fc_digit (n INT PRIMARY KEY);
  INSERT INTO fc_digit VALUES (0),(1),(2),(3),(4),(5),(6),(7),(8),(9);
  -- Copies allow a MySQL temporary table to appear once per statement.
  CREATE TEMPORARY TABLE fc_tens AS SELECT n FROM fc_digit;
  CREATE TEMPORARY TABLE fc_hundreds AS SELECT n FROM fc_digit;
  CREATE TEMPORARY TABLE fc_thousands AS SELECT n FROM fc_digit;
  CREATE TEMPORARY TABLE fc_n (n INT PRIMARY KEY);
  INSERT INTO fc_n SELECT a.n+10*b.n+100*c.n+1000*d.n
    FROM fc_digit a CROSS JOIN fc_tens b CROSS JOIN fc_hundreds c CROSS JOIN fc_thousands d;
  CREATE TEMPORARY TABLE fc_seat AS SELECT n FROM fc_n WHERE n < 20;

  START TRANSACTION;
  -- Historic participants have a hash of a discarded random password and
  -- synthetic addresses; the three interactive demo accounts remain available.
  INSERT INTO users (user_id,email,password_hash,full_name,role)
    SELECT 1000+n, CONCAT('archive.',n,'@form.example.invalid'),
      '$2y$10$olCM/7q5P7pLaFFvyaNTXu496F8TnIO/9iQhc9R13KVMfwkD6pyh.',CONCAT(
      ELT(1+MOD(n,10),'Alex','Jamie','Morgan','Taylor','Riley','Sam','Jordan','Casey','Emery','Harper'),' ',
      ELT(1+MOD(n DIV 10,10),'Chen','Lin','Liu','Xu','Wang','Li','Cai','Yang','Wu','Zhou')
    ), 'MEMBER' FROM fc_n WHERE n<2000;
  INSERT INTO orders (order_id,user_id,plan_id,method,request_key,status,created_at)
    SELECT 1000+n,1000+n,3,IF(MOD(n,4)=0,'OFFLINE','SIMULATED'),'archive-first','PAID',
      day_zero - INTERVAL (230+MOD(n,21)) DAY FROM fc_n WHERE n<2000;
  INSERT INTO payments (order_id,status,attempt_key,attempted_at,paid_at)
    SELECT order_id,'FAILED','first-failed',created_at,NULL FROM orders
      WHERE order_id>=1000 AND MOD(order_id,17)=0;
  INSERT INTO payments (order_id,status,attempt_key,attempted_at,paid_at)
    SELECT order_id,'SUCCEEDED','settlement',created_at + INTERVAL 1 MINUTE,created_at + INTERVAL 1 MINUTE
      FROM orders WHERE order_id>=1000;
  INSERT INTO memberships (order_id,starts_at,ends_at)
    SELECT order_id,paid_at,paid_at + INTERVAL 365 DAY FROM payments
      WHERE order_id>=1000 AND status='SUCCEEDED';
  -- 200 days, 3 rooms and 6 time slots per room/day. Each has a fixed coach.
  INSERT INTO class_sessions (session_id,course_id,coach_id,venue_id,starts_at,ends_at,capacity,status)
    SELECT 1000+n,1+MOD(n,3),1+MOD(n,3),1+MOD(n,3),
      day_zero - INTERVAL (201 - n DIV 18) DAY + INTERVAL (7+2*(MOD(n,18) DIV 3)) HOUR,
      day_zero - INTERVAL (201 - n DIV 18) DAY + INTERVAL (7+2*(MOD(n,18) DIV 3)) HOUR
        + INTERVAL IF(MOD(n,3)=2,45,60) MINUTE,
      20,'COMPLETED' FROM fc_n WHERE n<3600;
  -- Consecutive 20-seat blocks rotate through 2000 members. Three concurrent
  -- classes have disjoint member sets. Five percent cancel before the cutoff.
  INSERT INTO bookings (booking_id,user_id,session_id,status,booked_at,cancelled_at)
    SELECT 1000+20*(s.session_id-1000)+seat.n,
      1000+MOD(20*(s.session_id-1000)+seat.n,2000),s.session_id,
      IF(MOD(s.session_id+seat.n,20)=0,'CANCELLED_BY_MEMBER','CONFIRMED'),
      s.starts_at - INTERVAL (2+MOD(seat.n,9)) DAY,
      IF(MOD(s.session_id+seat.n,20)=0,s.starts_at - INTERVAL 4 HOUR,NULL)
      FROM class_sessions s CROSS JOIN fc_seat seat
      WHERE s.session_id>=1000 AND seat.n<15+MOD(s.session_id,6);
  INSERT INTO attendance (booking_id,status,checked_at)
    SELECT b.booking_id,IF(MOD(b.booking_id,9)=0,'ABSENT','PRESENT'),
      IF(MOD(b.booking_id,9)=0,NULL,s.starts_at - INTERVAL MOD(b.booking_id,21) MINUTE)
      FROM bookings b JOIN class_sessions s ON s.session_id=b.session_id
      WHERE b.booking_id>=1000 AND b.status='CONFIRMED';
  COMMIT;
END//
DELIMITER ;
CALL load_form_club_scale();
DROP PROCEDURE load_form_club_scale;
