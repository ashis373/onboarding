In short: No, storing username is NOT compulsory, but Yes, it is highly recommended to keep it.

1. Is it Compulsory? (No)
From a technical and database perspective, username is optional:

The true unique identifier that connects the two systems is hexa_uid (HexaOne) and moodle_user_id (Moodle).
Both have unique constraints:
sql
UNIQUE KEY uq_hexa_uid (hexa_uid),
UNIQUE KEY uq_moodle_user_id (moodle_user_id)
All core API queries (like launch.php, courses.php, employee_progress.php) look up users by ID:
sql
SELECT moodle_user_id FROM onboard_moodle_users WHERE hexa_uid = ?
The username can always be retrieved at any time by joining the main users table:
sql
SELECT u.username, m.moodle_user_id 
FROM users u 
JOIN onboard_moodle_users m ON m.hexa_uid = u.uid;
2. Why is keeping username recommended?
Reason	Benefit
Human Readability in DB	When you check phpMyAdmin or MySQL, you can instantly see that row 1 is ashisr and row 4 is arup without having to manually check what UID 205 or 64 stands for.
Moodle Troubleshooting	Moodle WebServices (core_user_get_users_by_field) search by username. Having username in this table makes it very easy to verify if the HexaOne username matches Moodle's username.
Zero Performance Cost	A VARCHAR(100) column costs almost zero disk space (~15 bytes per row) and does not slow down queries.
Self-Updating	api/moodle/sync_user.php automatically updates it on sync via ON DUPLICATE KEY UPDATE username = VALUES(username).
3. Conclusion & Recommendation
Do you need to change anything? No.
Keep username VARCHAR(100) NULL in onboard_moodle_users. It acts as a helpful reference and audit column for admins, while hexa_uid and moodle_user_id handle the actual synchronization.