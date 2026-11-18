
=== HexaLearn Onboard Database Update & Verification ===

[EXISTS] Column `sort_order` in `onboard_modules`.
[EXISTS] Column `is_active` in `onboard_modules`.
[EXISTS] Column `total_slides` in `onboard_modules`.
[EXISTS] Index `idx_active_sort` in `onboard_modules`.
[OK] Initialized sort_order defaults for courses.
[OK] Aligned Module 8 to 17 total slides.
[OK] Aligned Module 9 to 19 total slides.
[EXISTS] Column `slides_viewed` in `onboard_module_progress`.
[EXISTS] Column `total_slides` in `onboard_module_progress`.
[EXISTS] Column `visited_slides` in `onboard_module_progress`.
[EXISTS] Index `idx_omp_status_completed` in `onboard_module_progress`.
[FIXED] Dropped broken foreign key constraint `fk_progress_module`.
[OK] Re-created foreign key `fk_progress_module` pointing to `onboard_modules` (`id`).
[DELETED] Successfully deleted legacy rows 1353 and 1354 (User 64).
[OK] Initialized live progress rows in onboard_module_progress.
[OK] Session cache cleared.

[EXISTS] api/moodle/courses.php already configured for real-time INSERT ... ON DUPLICATE KEY UPDATE.

--- Live Moodle Telemetry Sync ---
[OK] Synced User 182 (anupj) -> Module 5: Slide 2/18 (11%) - in_progress
[OK] Synced User 182 (anupj) -> Module 5: Slide 3/18 (17%) - in_progress
[OK] Synced User 205 (ashisr) -> Module 8: Slide 10/17 (100%) - completed
[OK] Synced User 205 (ashisr) -> Module 9: Slide 16/19 (84%) - in_progress
[OK] Synced User 205 (ashisr) -> Module 7: Slide 11/19 (100%) - completed
[OK] Synced User 205 (ashisr) -> Module 6: Slide 8/10 (0%) - failed
[OK] Synced User 205 (ashisr) -> Module 5: Slide 12/18 (100%) - completed

--- Current Courses in `onboard_modules` ---
ID   | Title                            | Order  | Total Slides | Active
-----+----------------------------------+--------+--------------+-------
5    | Employee Onboarding 1 SCORM      | 5      | 18           | YES   
6    | Employee Assessment              | 6      | 10           | YES   
7    | Work Rules and Work Life         | 7      | 19           | YES   
8    | IT, Confidentiality &amp; Securi | 8      | 17           | YES   
9    | Onbording_5                      | 9      | 19           | YES   

--- Recent Progress in `onboard_module_progress` ---
ID   | User ID | Mod ID | Status      | Progress | Slides | Visited       
-----+---------+--------+-------------+----------+--------+----------------
6056 | 182     | 5      | in_progress | 17     % | 3 /18   | none          
6053 | 205     | 9      | in_progress | 84     % | 16/19   | 12,13,14,15,16
6052 | 205     | 8      | completed   | 100    % | 10/17   | none          
6051 | 205     | 7      | completed   | 100    % | 11/19   | none          
6050 | 205     | 6      | failed      | 17     % | 10/10   | none          
6049 | 205     | 5      | completed   | 100    % | 12/18   | none          

Database update and verification completed successfully!