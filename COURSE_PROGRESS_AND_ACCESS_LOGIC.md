# HexaLearn Onboard — Course Playing, Completion & Access Control Logic

This document records the authoritative frontend and backend logic governing course launching, progress tracking, Moodle LMS completion authority, sequential unlocking, and admin overrides in HexaLearn Onboard.

---

## 1. Authoritative Curriculum Ordering & Mapping
- **Source of Truth**: `onboard_modules` table (`is_active = 1`, `moodle_course_id > 0`).
- **Sorting**: Ordered strictly by `IFNULL(sort_order, id) ASC, id ASC` in [`api/moodle/courses.php`](api/moodle/courses.php) and `usort()` on `sort_order`.
- **Fallback SCORM/SCO Master Mapping**: `$courseMasterMap` in `api/moodle/courses.php` and `api/moodle/launch.php` maps each Moodle `course_id` to its primary `cmid`, launchable `scoid`, and SCORM `organization` identifier.
- **Hidden / Non-Roadmap Activities**: Inactive courses (`is_active = 0`, e.g., legacy assessment module `id = 6`) and Moodle `forum` / `label` / `chat` / `news` modules are excluded from the learner roadmap.

---

## 2. Course Launch & Window Coordination
- **Frontend Entry Points**: [`src/pages/Dashboard.tsx`](src/pages/Dashboard.tsx) and [`src/pages/CourseDetailPage.tsx`](src/pages/CourseDetailPage.tsx) (`openPlayer`).
- **Dedicated Window Launch**:
  - [`src/components/ActiveCourseModal.tsx`](src/components/ActiveCourseModal.tsx) (`useActiveCourseSession`) opens `/internal/hex-timesheet/onboard/play?...` in a named popup window (`HexaLearnScormPlayer`) using `window.open(playUrl, 'HexaLearnScormPlayer', features)`.
  - If the browser blocks popups (`window.open` returns `null`), the UI falls back to `ScormPlayerModal` inside the current tab.
- **Multi-Tab Synchronization**:
  - Opening or closing the player broadcasts `COURSE_LAUNCHED` / `COURSE_CLOSED` / `FOCUS_PLAYER` via `BroadcastChannel('hexalearn_scorm_channel')` and writes/clears `localStorage['hexalearn_active_course_session']` and `localStorage['hexalearn_scorm_last_closed']`.
  - All open Onboard tabs display `ActiveCourseModal` while a course window is active. When the player window closes (`window.closed` polling + `beforeunload` / `pagehide` in [`src/pages/ScormPlayerPage.tsx`](src/pages/ScormPlayerPage.tsx)), the modal dismisses across all tabs and triggers `triggerUnifiedRefresh()`.
- **Moodle SSO Launch (`api/moodle/launch.php`)**:
  - Validates employee session (`uid`, active `status = 0`), validates `cmid` + `scoid` + `courseid`, constructs encrypted/HMAC-signed SSO token for `local/ssologin/login.php`, and redirects directly into Moodle `/mod/scorm/player.php` (`display=popup`), bypassing Moodle's landing page.
  - Moodle `mod/scorm/player.php` injects clean embed CSS when `$displaymode === 'popup'` and suppresses `\core\session\manager::keepalive()` to avoid false 10-second timeout dialogs.

---

## 3. Completion & Progress Evaluation (Moodle LMS Primary)
Moodle LMS is the **primary authority** for user completion status in [`api/moodle/courses.php`](api/moodle/courses.php):

1. **Course-Level Completion (`core_enrol_get_users_courses`)**:
   - `$isMoodleCourseCompleted` is true **only** if Moodle itself returns `completed = true` or `progress >= 100`. Local `onboard_module_progress` rows are **never** promoted into `$isMoodleCourseCompleted`.
2. **SCORM Attempt & Track Verification (`mod_scorm_get_scorm_attempt_count` + `mod_scorm_get_scorm_sco_tracks`)**:
   - **Zero Moodle Attempts (`attemptscount === 0` and `!$isMoodleCourseCompleted`)**:
     - The employee has never opened that SCORM in Moodle.
     - Status is strictly `not_started`, `progress_percent = 0`, `current_slide = 0`, `is_completed = false`.
     - `mod_scorm_get_scorm_sco_tracks` and `core_completion_get_activities_completion_status` are skipped for 0-attempt courses to reduce Moodle REST traffic.
   - **Active Moodle Attempts (`attemptscount >= 1`)**:
     - Reads live `cmi.core.lesson_status` (plus previous attempts if `attemptNum > 1`), `cmi.core.score.raw`, `cmi.core.lesson_location`, `cmi.suspend_data`, and `cmi.core.total_time`.
     - Marked `completed` (`100%`, `is_completed = true`, all slides completed) if:
       - Moodle `lesson_status` is `completed` or `passed`, **OR**
       - Moodle activity completion (`core_completion_get_activities_completion_status`) returns state `1` or `2`, **OR**
       - For non-assessment modules with an active attempt (`attemptNum >= 1`), `$curSlide >= $totalSlideCount` (derived from Moodle bookmark/`suspend_data` or verified `visited_slides` via [`api/moodle/track_slide.php`](api/moodle/track_slide.php)).
     - Otherwise, if Moodle status is `incomplete` / `browsed` / `suspend` (e.g., `Page 3/18`), status remains `in_progress` with calculated slide percentage (`min(99, max(5, round(curSlide / totalSlideCount * 100)))`), **never** overridden by stale local DB `is_completed = 1`.
   - **Assessments**:
     - Require `score >= passing_score` (default `80`) or `passed` / `completed` status; scores below passing threshold are evaluated as `failed` and remain retakeable.
3. **Real-Time Slide Heartbeat (`api/moodle/track_slide.php`)**:
   - Records unique visited slides into `onboard_module_progress.visited_slides` and maps legacy Storyline `module_id = 6` with `total_slides = 20` to `module_id = 11` (POSH).
   - Reaching the final slide (`uniqueCount >= effTotalSlides || currentSlide >= effTotalSlides`) sets `progress_percent = 100`, `is_completed = 1`, `status = 'completed'`.
4. **Moodle Unavailable Fallback**:
   - Only when Moodle WS calls return an error / rate-limit (`!$moodleTracksVerified`) does `courses.php` fall back to `onboard_module_progress` and preserve existing monotonic progress.
5. **Database Sync & Stale Row Reconciliation (`courses.php` & `api/moodle/sync_user.php`)**:
   - When live Moodle verification succeeds (`moodle_verified = true`) and confirms a course is `not_started` or `in_progress`, `courses.php` and `persistMoodleTrackingToLocalSql()` reconcile `onboard_module_progress` to match Moodle's real state instead of locking stale `is_completed = 1` values.

---

## 4. When a Learner Can Access, View, or Launch a Course
Evaluated sequentially in [`api/moodle/courses.php`](api/moodle/courses.php) (`sequential_locking` setting, default `true`) and mirrored in [`src/pages/Dashboard.tsx`](src/pages/Dashboard.tsx) and [`src/pages/CourseDetailPage.tsx`](src/pages/CourseDetailPage.tsx):

| Course State | Can Open Course Details & Progress (`/modules/:id`)? | Can Launch / Play SCORM Player? | Locking / Display Behavior |
| :--- | :--- | :--- | :--- |
| **Completed** (`status = 'completed'`, no admin override) | **Yes** (allowed on Dashboard, `/modules`, and Your Journey) | **No** (`is_locked = true` for relaunch; `handleLaunchModule` blocks launch with manager-approval toast) | Card shows `Completed` badge and disabled `Completed · Locked` button; progress rows (`Slide Progress`) are non-launching. |
| **Completed + Admin Override** (`admin_unlocked = true`) | **Yes** | **Yes** | Displays `Relaunch Course` / `Relaunch Assessment Now` button. |
| **Next Playable Course** (all preceding courses completed; `not_started` / `in_progress`) | **Yes** | **Yes** (`is_locked = false`) | Uses `$allPrevCompleted`: only the **first** uncompleted course in `sort_order` is unlocked (`Start` / `Continue Learning`). |
| **Failed Assessment** (`status = 'failed'`) | **Yes** | **Yes** (`is_locked = false`) | Remains unlocked for retakes (`newattempt=on`). |
| **Future Course** (at least one preceding course is incomplete) | **No** (`cursor-not-allowed`, lock toast on click) | **No** (`status = 'locked'`, `is_locked = true`) | Once any course is incomplete, `$allPrevCompleted` flips `false` and **all** subsequent courses remain locked unless individually granted an `onboard_course_unlock_overrides` row (`is_unlocked = 1`). |

---

## 5. Admin Reporting & Image Proxy Policies
- **Strict Hexa UID Isolation**: Admin sync scripts ([`api/admin/employee_progress.php`](api/admin/employee_progress.php), [`api/admin/course_access.php`](api/admin/course_access.php)) query and update `onboard_module_progress` strictly by `user_id = {$employeeHexaUid}` (never `OR user_id = {$moodleUserId}`), preventing cross-employee data collisions when a Moodle user ID numerically matches another employee's Hexa `uid`.
- **Zero-REST Image Proxy ([`api/moodle/course_image.php`](api/moodle/course_image.php))**: Serves locally cached cover images (`courses/course_{id}.*`) or an immediate local SVG fallback without calling Moodle REST APIs, eliminating image-driven Moodle rate limits.

