onboard_module_progress
0 rowsMOODLE ACTIVE96.0 KBInnoDB
[📊Browse Data](https://hexalearn.com/internal/hex-timesheet/onboard/p.php?mode=active&table=onboard_module_progress&tab=data)[🛠Structure (24)](https://hexalearn.com/internal/hex-timesheet/onboard/p.php?mode=active&table=onboard_module_progress&tab=schema)[📜SQL DDL](https://hexalearn.com/internal/hex-timesheet/onboard/p.php?mode=active&table=onboard_module_progress&tab=sql)[📥CSV](https://hexalearn.com/internal/hex-timesheet/onboard/p.php?export=csv&table=onboard_module_progress&q=)
✅Active Live Table: Used directly by the Onboarding portal & Moodle LMS sync for real-time progress.
Column
Data Type
Nullable
Key
Default Value
Extra
Comment
id
int(11)
NO
PRIMARY KEY
NULL
auto_increment
-
user_id
int(11)
NO
INDEX (MUL)
NULL
-
-
module_id
int(11)
NO
INDEX (MUL)
NULL
-
-
is_completed
tinyint(1)
NO
-
0
-
-
completed_at
datetime
YES
-
NULL
-
-
started_at
datetime
YES
-
NULL
-
-
last_viewed_at
datetime
YES
INDEX (MUL)
NULL
-
-
progress_pct
tinyint(3) unsigned
YES
-
0
-
-
time_spent_seconds
int(10) unsigned
YES
-
0
-
-
status
enum('not_started','in_progress','completed','failed')
YES
-
'not_started'
-
-
progress_percent
int(11)
YES
-
0
-
0 to 100%
highest_progress_percent
int(11)
YES
-
0
-
Monotonic high-water mark
score
decimal(5,2)
YES
-
NULL
-
Latest assessment score
highest_score
decimal(5,2)
YES
-
NULL
-
Best assessment score achieved
passing_score
int(11)
YES
-
80
-
-
total_time
varchar(32)
YES
-
'00:00:00'
-
-
total_time_seconds
int(10) unsigned
YES
-
0
-
-
attempts
int(11)
YES
-
0
-
-
suspend_data
text
YES
-
NULL
-
SCORM bookmark & slide tracking state
lesson_location
varchar(255)
YES
-
NULL
-
Last slide or location visited
slides_viewed
int(10) unsigned
YES
-
0
-
Number of slides viewed
total_slides
int(10) unsigned
YES
-
0
-
Total slides in module
last_checked_at
datetime
YES
-
NULL
-
-
last_synced_at
datetime
YES
-
current_timestamp()
on update current_timestamp() onboard_moodle_users
3 rowsMOODLE ACTIVE48.0 KBInnoDB
[📊Browse Data](https://hexalearn.com/internal/hex-timesheet/onboard/p.php?mode=active&table=onboard_moodle_users&tab=data)[🛠Structure (9)](https://hexalearn.com/internal/hex-timesheet/onboard/p.php?mode=active&table=onboard_moodle_users&tab=schema)[📜SQL DDL](https://hexalearn.com/internal/hex-timesheet/onboard/p.php?mode=active&table=onboard_moodle_users&tab=sql)[📥CSV](https://hexalearn.com/internal/hex-timesheet/onboard/p.php?export=csv&table=onboard_moodle_users&q=)
✅Active Live Table: Used directly by the Onboarding portal & Moodle LMS sync for real-time progress.
SearchShowing page 1 of 1 (Limit: 25) 25 / page 50 / page 100 / page
id PRI
hexa_uid UNI
username
moodle_user_id UNI
sync_status
last_synced_at
notes
created_at
updated_at
1
205
ashisr
4
Linked
2026-10-23 06:50:18
Auto-synced and enrolled in Moodle
2026-10-14 12:49:57
2026-10-23 06:50:18
2
182
anupj
5
Linked
2026-10-23 04:38:35
Auto-synced and enrolled in Moodle
2026-10-14 12:55:35
2026-10-23 04:38:35
4
64
arup
6
Linked
2026-10-23 06:18:42
Auto-synced and enrolled in Moodle
2026-10-16 05:26:39
2026-10-23 06:18:42  nboard_modules
3 rowsMOODLE ACTIVE32.0 KBInnoDB
[📊Browse Data](https://hexalearn.com/internal/hex-timesheet/onboard/p.php?mode=active&table=onboard_modules&tab=data)[🛠Structure (17)](https://hexalearn.com/internal/hex-timesheet/onboard/p.php?mode=active&table=onboard_modules&tab=schema)[📜SQL DDL](https://hexalearn.com/internal/hex-timesheet/onboard/p.php?mode=active&table=onboard_modules&tab=sql)[📥CSV](https://hexalearn.com/internal/hex-timesheet/onboard/p.php?export=csv&table=onboard_modules&q=)
✅Active Live Table: Used directly by the Onboarding portal & Moodle LMS sync for real-time progress.
SearchShowing page 1 of 1 (Limit: 25) 25 / page 50 / page 100 / page
id PRI
title
type
description
video_url
file_url
duration_minutes
department_id
designation_id
is_active
created_at
updated_at
roadmap_day
moodle_course_id FK
moodle_cmid
moodle_scoid
passing_score
5
Employee Onboarding 1 SCORM
scorm
NULL
NULL
NULL
45
NULL
NULL
1
2026-10-16 09:36:04
2026-10-16 09:36:04
1
2
2
2
80
6
Employee Assessment
assessment
NULL
NULL
NULL
20
NULL
NULL
1
2026-10-16 09:36:04
2026-10-16 09:36:04
1
3
14
4
80
7
Work Rules and Work Life
scorm
NULL
NULL
NULL
30
NULL
NULL
1
2026-10-16 09:36:04
2026-10-16 09:36:04
1
4
3
3
80 nboard_assessment_attempts
3 rowsMOODLE ACTIVE48.0 KBInnoDB
[📊Browse Data](https://hexalearn.com/internal/hex-timesheet/onboard/p.php?mode=active&table=onboard_assessment_attempts&tab=data)[🛠Structure (15)](https://hexalearn.com/internal/hex-timesheet/onboard/p.php?mode=active&table=onboard_assessment_attempts&tab=schema)[📜SQL DDL](https://hexalearn.com/internal/hex-timesheet/onboard/p.php?mode=active&table=onboard_assessment_attempts&tab=sql)[📥CSV](https://hexalearn.com/internal/hex-timesheet/onboard/p.php?export=csv&table=onboard_assessment_attempts&q=)
✅Active Live Table: Used directly by the Onboarding portal & Moodle LMS sync for real-time progress.
SearchShowing page 1 of 1 (Limit: 25) 25 / page 50 / page 100 / page
id PRI
assessment_id FK
user_id FK
score
max_score
is_passed
started_at
submitted_at
is_draft
module_id
attempt_number
result
total_time
total_time_seconds
attempted_at
5
6
64
0.00
0
NULL
2025-09-19 07:12:27
NULL
1
NULL
1
Incomplete
00:00:00
0
2026-10-16 09:36:04
6
7
64
0.00
0
NULL
2025-10-15 10:17:58
NULL
1
NULL
1
Incomplete
00:00:00
0
2026-10-16 09:36:04
7
6
205
0.00
0
NULL
2026-10-09 12:04:14
NULL
1
NULL
1
Incomplete
00:00:00
0
2026-10-16 09:36:04  
nboard_moodle_users
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
updated_at	timestamp	YES	-	current_timestamp()	on update current_timestamp() 
onboard_modules
3 rows
MOODLE ACTIVE
32.0 KB
InnoDB
📊
Browse Data
🛠
Structure (17)
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
department_id	int(11)	YES	-	NULL	-	-
designation_id	int(11)	YES	-	NULL	-	-
is_active	tinyint(1)	NO	-	1	-	-
created_at	timestamp	YES	-	current_timestamp()	-	-
updated_at	timestamp	YES	-	current_timestamp()	on update current_timestamp()	-
roadmap_day	int(11)	NO	-	1	-	-
moodle_course_id	int(11)	YES	INDEX (MUL)	NULL	-	Moodle course ID (e.g. 2, 3, 4)
moodle_cmid	int(11)	YES	-	NULL	-	Moodle course module ID
moodle_scoid	int(11)	YES	-	NULL	-	Moodle launchable SCO ID
passing_score	int(11)	YES	-	80	-	-
nboard_module_sessions
193 rows
MOODLE ACTIVE
240.0 KB
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
Search within this table...
Search
Showing page 1 of 8 (Limit: 25)

25 / page
id PRI	user_id FK	module_id	session_time	session_seconds	slides_viewed	progress_percent	score	status	lesson_location	suspend_data	recorded_at
1	205	5	00:42:52.28	2572	0	60	NULL	in_progress	NULL	2X110c304050607080USR1001311J0101101111012110131101411015110101101111013110G700000001^v_player.5nTReJANMxe.6izGkt0j5L800400000	2026-10-16 09:38:24
2	205	6	00:01:20.01	80	0	17	16.66	failed	NULL	2qr10a30906080a0KIH101131110x0101111011101111110112111011311100~2k2t000000000000000000000000000001^1^1^1^1^1^1^1^1^1^1^1^1^101010101010101016101010101010101010141013101010H_player.6FWI7wSIi0v.6N3…	2026-10-16 09:38:24
3	205	7	00:26:22.64	1582	0	40	NULL	in_progress	NULL	2N11043090lji1001611a01011016110~29180000000010101010101010101010101010101010v_player.6WNZC3EyUyl.6gh6bvS57540030000	2026-10-16 09:38:24
4	205	5	00:42:52.28	2572	0	60	NULL	in_progress	NULL	2X110c304050607080USR1001311J0101101111012110131101411015110101101111013110G700000001^v_player.5nTReJANMxe.6izGkt0j5L800400000	2026-10-16 09:49:52
5	205	6	00:01:20.01	80	0	17	16.66	failed	NULL	2qr10a30906080a0KIH101131110x0101111011101111110112111011311100~2k2t000000000000000000000000000001^1^1^1^1^1^1^1^1^1^1^1^1^101010101010101016101010101010101010141013101010H_player.6FWI7wSIi0v.6N3…	2026-10-16 09:49:52