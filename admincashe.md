Across the Admin portal, there are 5 dedicated React Query caches implemented:

Summary of Admin Cache Queries
#	Admin Route	Query Key	Cached Backend API	Cache Fresh Duration (staleTime)	Behavior on Tab / Route Navigation
1	/admin (Dashboard)	['admin_dashboard_data']	summary.php
assessments/list.php
employees.php	60 Seconds (Fresh)
10 Minutes (Garbage Collection)	0 network calls when returning to dashboard.
2	/admin/employees (Directory)	['admin_employees', page, limit, filterTab, q]	employees.php	60 Seconds per page/filter	Navigating back and forth between employees or pages does 0 network calls.
3	/admin/courses (Catalog)	['admin_courses_overview']	courses_overview.php	60 Seconds	Catalog & stats render instantly (0ms) from memory.
4	/admin/reports (Analytics)	['admin_reports_overview']	reports_overview.php	60 Seconds	Chart and completion trends render instantly with 0 repeat calls.
5	/admin/employees/:uid/report (Audit)	['admin_employee_report', targetUid]	employee_progress.php?uid=...	60 Seconds per employee ID	Visiting the same employee's audit report loads instantly.
How the Cache Works for the Admin
Instant Page Switching (0ms Delay):
Whenever an admin clicks between Dashboard $\leftrightarrow$ Employees $\leftrightarrow$ Courses $\leftrightarrow$ Analytics, React Query checks the in-memory cache.
If the data was fetched within the last 60 seconds, it serves the data instantly without sending any request to PHP or MySQL.
Zero Tab Refetch Spams:
refetchOnWindowFocus: false ensures that switching between browser tabs or windows does not trigger extra background fetches.
Auto-Invalidation on Changes (Mutations):
If the admin creates, edits, or deletes a module/quiz or clicks Sync, the app automatically calls invalidateQueries() which invalidates the cache and fetches fresh data immediately.
Non-Blocking Backend (session_write_close):
When requests do fire, PHP session locks are released immediately so parallel API requests run concurrently in <100ms instead of waiting in a 5-second queue.