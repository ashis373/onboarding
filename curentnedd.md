Custom PHP + MySQL login   ,Inside the existing HexaOne Onboarding dashboard   only ok 

Perfect. We will connect your existing Custom PHP + MySQL login to Moodle, and show the courses inside your existing HexaOne Onboarding dashboard. We will keep your current login system and React UI.

Step 1 — Connect the logged-in employee to Moodle

Your project will work like this:

HexaOne PHP + MySQL

Employee logs in using existing credentials

Onboarding PHP backend

Identifies the authenticated employee securely

Moodle REST API

Finds the Moodle user and retrieves their enrolled courses

Existing Onboarding dashboard

Shows courses, progress and Continue Learning

What I need you to do first

Open your existing project folder:

D:\xaamp\htdocs\onboard

Find these files:

src/main.tsx — your onboarding dashboard and frontend.

config.php — if present, your PHP configuration.

The PHP file that checks the employee's login/session.

Please send me the PHP login/session-check file first, or paste its code here. If you aren't sure which file it is, open the project folder and share a screenshot of the PHP files.

Do not share passwords, database credentials, or your Moodle API token.

Once I see how your current login identifies the employee, I can show you exactly where to connect Moodle, which PHP file to create, and how to display the employee's actual courses.

Important: We will first make the course dashboard work. Then we will configure SSO so employees can open Moodle courses without logging in a second time.
<!-- 
----------------------------- -->
https://lms.moodle.hexalearn.com/webservice/rest/server.php 

POST
https://lms.moodle.hexalearn.com/webservice/rest/server.php

Params
Authorization

Headers(9)
Body

Scripts
Settings
wstoken

492213......................

wsfunction

mod_scorm_get_scorm_sco_tracks

moodlewsrestformat

json

scoid

2

userid

2

attempt

1

Key
Value
Pretty
Raw
Preview
<!-- 1
----------- -->
{"data":{"attempt":1,"tracks":[{"element":"score_raw","value":""},{"element":"status","value":"incomplete"},{"element":"total_time","value":"01:47:33.28"},{"element":"session_time","value":"00:00:00"},{"element":"timemodified","value":"1791891229"},{"element":"cmi.core.exit","value":"suspend"},{"element":"cmi.core.lesson_status","value":"incomplete"},{"element":"cmi.core.total_time","value":"01:47:33.28"},{"element":"cmi.suspend_data","value":"2B110a30405060n0Ayx1001k11p0101101111012110131101k110G700000001^v_player.5nTReJANMxe.5fpi1PlNEVu00400000"},{"element":"x.start.time","value":"1791875678"},{"element":"userid","value":2},{"element":"scoid","value":"2"}]},"warnings":[]} 
<!-- ----------------- -->
metosed post  
Great. Your api/auth/status.php already retrieves the information we need to connect the logged-in employee to Moodle.

It already provides:

uid — HexaOne employee database ID.

emp_code — employee code.

ofc_mail — corporate email.

firstname, lastname — employee name.

department, designation — employee details.

The existing PHP session determines which employee is logged in.

We do not need to change your existing login system. We can build the Moodle integration around it.

Step 2 — Link one employee to one Moodle account

HexaOne employee

Read the authenticated user's uid, emp_code, and ofc_mail from the existing PHP backend.

Moodle account mapping

Store the corresponding Moodle user ID against the HexaOne employee. Do not assume both systems use the same numeric ID.

Onboarding dashboard

Retrieve the employee's enrolled Moodle courses and their real completion status.
Step 3 — Check the employee's enrolled courses

In Postman, keep your existing endpoint and POST method.

Use Body → x-www-form-urlencoded with these fields:

Key

	

Value




wstoken

	

Your existing Moodle token — keep private




wsfunction

	

core_enrol_get_users_courses




moodlewsrestformat

	

json




userid

	

4

Remove the unrelated fields from your previous request: scoid, attempt, field, and values[0].

Click Send.

This will tell us which courses Moodle currently associates with user 4.

Send me the response (you can omit personal details). Then we'll connect the course list to your existing Onboarding dashboard. We'll handle automatic Moodle login (SSO) after the course list works.

[{"id":2,"shortname":"EMP-ONBOARD","fullname":"Employee Onboarding","displayname":"Employee Onboarding","enrolledusercount":3,"idnumber":"EMP-MOD-01","visible":1,"summary":"<p><span>Employee <\/span><span>onboarding <\/span><span>and <\/span><span>training <\/span><span>modules.<\/span><\/p>","summaryformat":1,"format":"topics","courseimage":"https:\/\/lms.moodle.hexalearn.com\/pluginfile.php\/17\/course\/generated\/course.svg","showgrades":true,"lang":"","enablecompletion":true,"completionhascriteria":false,"completionusertracked":true,"category":1,"progress":null,"completed":false,"startdate":1791932400,"enddate":1823468400,"marker":0,"lastaccess":1791891687,"isfavourite":false,"hidden":false,"overviewfiles":[],"showactivitydates":true,"showcompletionconditions":true,"timemodified":1791890077}]  

Perfect! ✅ The Moodle API confirms that Test Employee (Moodle user ID 4) is enrolled in Employee Onboarding (course ID 2).

Step 3 result

Employee Onboarding

Course shortname: EMP-ONBOARD

Course ID: 2 · Enrolled users: 3

Visible and enrolled

Completion tracking

Enabled

Course completion

Not completed

The progress: null value means this response does not provide a numeric progress percentage. We'll need to retrieve the SCORM tracking data to calculate meaningful progress.

Step 4 — Connect this to your existing dashboard

Your PHP backend will eventually do this:

Read the authenticated employee from the existing HexaOne session.

Look up that employee's mapped Moodle user ID.

Call Moodle's core_enrol_get_users_courses securely.

Return the course list to React.

Display course information and real learning progress in your existing Onboarding dashboard.

The Moodle API token must remain on the PHP server, never in src/main.tsx or src/pages/Dashboard.tsx.

One important check before we write code

Your Moodle test account is user 4, but we have not yet confirmed that it belongs to the employee currently logged into HexaOne.

Please open this URL while logged into your existing HexaOne portal:

https://hexalearn.com/internal/hex-timesheet/onboard/api/auth/status.php

If that endpoint returns the employee profile, tell me whether it includes uid, emp_code, and ofc_mail. Don't paste the full response because it contains personal informat 
Great. We have enough information to move toward the PHP integration. Your existing api/auth/status.php already retrieves the HexaOne employee's uid, emp_code, and ofc_mail, and your Moodle API successfully returns the employee's enrolled course.

One thing is still unverified: Moodle User ID 4 belongs to ashiskrout1@gmail.com, while the example HexaOne profile shows ashis@hexalearn.com. We should not assume these are the same person.

Next step: Create the Moodle bridge

I recommend adding a new PHP endpoint in your onboarding project:

D:\xaamp\htdocs\onboard\api\moodle\courses.php

It will:

Validate the existing HexaOne PHP session.

Read the logged-in employee's identity from the database.

Find the mapped Moodle user ID.

Call Moodle's REST API from PHP, keeping the token on the server.

Return that employee's enrolled courses as JSON for your existing React dashboard.

Before connecting progress tracking, confirm the employee-to-Moodle mapping. We can initially use a small mapping table with the HexaOne uid and Moodle user ID, rather than relying on matching email addresses indefinitely.

Your next action: In your current HexaOne session, verify whether the logged-in employee's emp_code is HL198 and whether the Moodle account you intend to use is User ID 4. You can simply reply “same employee” or “different employee.”

Then we can implement the bridge using the correct account, test Course ID 2, and connect the returned course data to the existing dashboard.


Yes, correct. Your HexaOne UID (205) is the unique identifier for that employee in your HexaOne database. We should use it as the primary employee reference for the Moodle integration.

The Moodle user ID is a separate identifier, so we need an explicit mapping between the two.

Field

	

Example

	

Purpose




HexaOne UID

	

205

	

Identifies the employee in HexaOne




Moodle user ID

	

4 or another verified ID

	

Identifies the employee in Moodle




Mapping status

	

Linked / Pending

	

Tracks whether the accounts are connected

Recommended database design

Create a table named onboard_moodle_users with these fields:

id — mapping record ID

hexalearn_uid — unique HexaOne UID

moodle_user_id — unique Moodle user ID

status — linked or pending

created_at and updated_at

Important: We should not assign Moodle user ID 4 to UID 205 until we verify that both accounts belong to the same employee.

Once the table is ready, the PHP backend can look up the mapping using the authenticated session's UID, fetch the employee's enrolled Moodle courses, and return them to your existing React dashboard.

This design supports all 50 employees without hardcoding individual IDs in your application code.