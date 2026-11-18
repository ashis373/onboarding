onboard_modules
5 rows
MOODLE ACTIVE
48.0 KB
InnoDB
📊
Browse Data
🛠
Structure (18)
📜
SQL DDL
📥
CSV
✅
Active Live Table: Used directly by the Onboarding portal & Moodle LMS sync for real-time progress.
Column	Data Type	Nullable	Key	Default Value	Extra	Comment
id	int(11)	NO	PRIMARY KEY	NULL	auto_increment	-
title	varchar(255)	NO	-	NULL	-	-
type	enum('scorm','assessment','video','pdf','document')	YES	-	'scorm'	-	-
description	text	YES	-	NULL	-	-
video_url	varchar(512)	YES	-	NULL	-	-
file_url	varchar(512)	YES	-	NULL	-	-
duration_minutes	int(11)	YES	-	NULL	-	-
total_slides	int(11)	NO	-	10	-	Total slides for SCORM progress calculation
department_id	int(11)	YES	-	NULL	-	-
designation_id	int(11)	YES	-	NULL	-	-
sort_order	int(11)	YES	-	0	-	Drag-and-drop course sequence order
is_active	tinyint(1)	NO	-	1	-	1 = Visible to employees, 0 = Hidden from employees
created_at	timestamp	YES	-	current_timestamp()	-	-
updated_at	timestamp	YES	-	current_timestamp()	on update current_timestamp()	-
roadmap_day	int(11)	NO	-	1	-	-
moodle_course_id	int(11)	YES	INDEX (MUL)	NULL	-	Moodle course ID (e.g. 2, 3, 4)
moodle_cmid	int(11)	YES	-	NULL	-	Moodle course module ID
moodle_scoid	int(11)	YES	-	NULL	-	Moodle launchable SCO ID
passing_score	int(11)	YES	-	80	-	-

---

onboard_moodle_users
3 rows
MOODLE ACTIVE
48.0 KB
InnoDB
📊
Browse Data
🛠
Structure (9)
📜
SQL DDL
📥
CSV
✅
Active Live Table: Used directly by the Onboarding portal & Moodle LMS sync for real-time progress.
Column	Data Type	Nullable	Key	Default Value	Extra	Comment
id	int(11)	NO	PRIMARY KEY	NULL	auto_increment	-
hexa_uid	int(11)	NO	UNIQUE	NULL	-	-
username	varchar(100)	YES	-	NULL	-	-
moodle_user_id	int(11)	YES	UNIQUE	NULL	-	-
sync_status	enum('Not linked','Pending verification','Linked','Error')	YES	-	'Not linked'	-	-
last_synced_at	datetime	YES	-	NULL	-	-
notes	varchar(255)	YES	-	NULL	-	-
created_at	timestamp	YES	-	current_timestamp()	-	-
updated_at	timestamp	YES	-	current_timestamp()	on update current_timestamp()	-

---

onboard_module_progress
2 rows
MOODLE ACTIVE
128.0 KB
InnoDB
📊
Browse Data
🛠
Structure (24)
📜
SQL DDL
📥
CSV
✅
Active Live Table: Used directly by the Onboarding portal & Moodle LMS sync for real-time progress.
Column	Data Type	Nullable	Key	Default Value	Extra	Comment
id	int(11)	NO	PRIMARY KEY	NULL	auto_increment	-
user_id	int(11)	NO	INDEX (MUL)	NULL	-	-
module_id	int(11)	NO	INDEX (MUL)	NULL	-	-
is_completed	tinyint(1)	NO	-	0	-	-
completed_at	datetime	YES	-	NULL	-	-
started_at	datetime	YES	-	NULL	-	-
last_viewed_at	datetime	YES	INDEX (MUL)	NULL	-	-
progress_pct	tinyint(3) unsigned	YES	-	0	-	-
time_spent_seconds	int(10) unsigned	YES	-	0	-	-
status	enum('not_started','in_progress','completed','failed')	YES	-	'not_started'	-	-
progress_percent	int(11)	YES	-	0	-	0 to 100%
highest_progress_percent	int(11)	YES	-	0	-	Monotonic high-water mark
score	decimal(5,2)	YES	-	NULL	-	Latest assessment score
highest_score	decimal(5,2)	YES	-	NULL	-	Best assessment score achieved
passing_score	int(11)	YES	-	80	-	-
total_time	varchar(32)	YES	-	'00:00:00'	-	-
total_time_seconds	int(10) unsigned	YES	-	0	-	-
attempts	int(11)	YES	-	0	-	-
suspend_data	text	YES	-	NULL	-	SCORM bookmark & slide tracking state
lesson_location	varchar(255)	YES	-	NULL	-	Last slide or location visited
slides_viewed	int(10) unsigned	YES	-	0	-	Number of slides viewed
total_slides	int(10) unsigned	YES	-	0	-	Total slides in module
last_checked_at	datetime	YES	-	NULL	-	-
last_synced_at	datetime	YES	-	current_timestamp()	on update current_timestamp()

---

onboard_module_sessions
416 rows
MOODLE ACTIVE
432.0 KB
InnoDB
📊
Browse Data
🛠
Structure (12)
📜
SQL DDL
📥
CSV
✅
Active Live Table: Used directly by the Onboarding portal & Moodle LMS sync for real-time progress.
Column	Data Type	Nullable	Key	Default Value	Extra	Comment
id	int(11)	NO	PRIMARY KEY	NULL	auto_increment	-
user_id	int(11)	NO	INDEX (MUL)	NULL	-	-
module_id	int(11)	NO	-	NULL	-	-
session_time	varchar(32)	YES	-	'00:00:00'	-	-
session_seconds	int(10) unsigned	YES	-	0	-	-
slides_viewed	int(10) unsigned	YES	-	0	-	-
progress_percent	int(11)	YES	-	0	-	-
score	decimal(5,2)	YES	-	NULL	-	-
status	varchar(32)	YES	-	'in_progress'	-	-
lesson_location	varchar(255)	YES	-	NULL	-	-
suspend_data	text	YES	-	NULL	-	-
recorded_at	datetime	YES	-	current_timestamp()

---

onboard_assessment_attempts
2 rows
MOODLE ACTIVE
80.0 KB
InnoDB
📊
Browse Data
🛠
Structure (15)
📜
SQL DDL
📥
CSV
✅
Active Live Table: Used directly by the Onboarding portal & Moodle LMS sync for real-time progress.
Column	Data Type	Nullable	Key	Default Value	Extra	Comment
id	int(11)	NO	PRIMARY KEY	NULL	auto_increment	-
assessment_id	int(11)	NO	INDEX (MUL)	NULL	-	-
user_id	int(11)	NO	INDEX (MUL)	NULL	-	-
score	decimal(5,2)	YES	-	NULL	-	-
max_score	int(11)	NO	-	0	-	-
is_passed	tinyint(1)	YES	-	NULL	-	-
started_at	datetime	NO	-	current_timestamp()	-	-
submitted_at	datetime	YES	-	NULL	-	-
is_draft	tinyint(1)	NO	-	0	-	1 if this is a draft attempt (not counted), 0 if submitted
module_id	int(11)	YES	-	NULL	-	Links to onboard_modules.id
attempt_number	int(11)	NO	-	1	-	-
result	enum('Passed','Failed','Incomplete')	YES	-	'Incomplete'	-	-
total_time	varchar(32)	YES	-	'00:00:00'	-	-
total_time_seconds	int(10) unsigned	YES	-	0	-	-
attempted_at	datetime	YES	-	current_timestamp() 

---

onboard_settings
3 rows
MOODLE ACTIVE
16.0 KB
InnoDB
📊
Browse Data
🛠
Structure (2)
📜
SQL DDL
📥
CSV
✅
Active Live Table: Used directly by the Onboarding portal & Moodle LMS sync for real-time progress.
Column	Data Type	Nullable	Key	Default Value	Extra	Comment
key	varchar(128)	NO	PRIMARY KEY	NULL	-	-
value	text	YES	-	NULL	-	-
