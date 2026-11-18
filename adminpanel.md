For your admin page at /hex-timesheet/onboard/admin, I recommend keeping exactly these five sidebar items:

Dashboard · Courses · User Tracking · Reports · Settings

You do not need separate Assessment Results and Employee Tracking menu items because those features can fit naturally into User Tracking and Reports.
1. What each admin page should show

Dashboard — Organization overview

Show four summary cards:
- Total employees enrolled in onboarding
- Employees who completed onboarding
- Employees currently in progress
- Employees needing attention (failed assessments or overdue requirements, where tracked)
Below the cards, show a completion-rate chart, a short list of employees needing attention, and recent onboarding activity. Use real data from your existing employee system and Moodle integration.




Courses — Moodle course overview
- All available onboarding courses and activities
- Course name, type, and enrollment information
- Number of employees assigned or enrolled
- Course completion statistics
- A link to view the course in Moodle
Keep course management in Moodle unless your integration explicitly supports editing courses from this portal.




User Tracking — Individual employee progress

Use a searchable, filterable table with:
Employee	Department	Progress	Status	Action
Employee name	Department	Percentage	In progress	View details
Clicking View details should open that employee's assigned modules, completion status, assessment results, and attempt history. Keep this information on the employee detail view instead of displaying every detail in the main table.




Reports — Organization-wide performance
- Overall onboarding completion rate
- Course completion rates
- Assessment pass/fail rates
- Average training time, if available
- Department-level comparisons, if department data exists
- Exportable reports, such as CSV
This is where admins analyze overall performance rather than inspect one employee at a time.




Settings — Portal configuration
- Moodle connection and API health status
- Onboarding rules and completion requirements
- Assessment passing threshold, if configurable through your application
- User access and permissions
- Data refresh settings
Do not expose Moodle credentials, API secrets, or sensitive configuration values in the browser.



2. Recommended admin dashboard layout

Admin Dashboard
Employee onboarding overview

Moodle Connected

Total Employees
—


Completed
—


In Progress
—


Needs Attention
—



Employee onboarding status
A summary chart showing completed, in-progress, and not-started employees.





Illustrative chart only — replace with actual employee counts.

Employees needing attention
Show only employees with a failed required assessment, incomplete mandatory training, or an overdue requirement when that information is available.

Select an employee to open User Tracking details.


The dashes above intentionally represent data that has not been retrieved.
3. How to avoid repeating information
Information	Single primary location
Organization summary	Dashboard
Course catalogue	Courses
Individual employee progress	User Tracking
Organization-wide analytics	Reports
Configuration	Settings
My final recommendation: Keep your five-item sidebar. Make the Dashboard an organization-wide summary, User Tracking the place for employee-level details, and Reports the place for aggregated performance. Pull learning and assessment data from Moodle, employee information from your existing employee database, and only show metrics your backend can reliably calculate.