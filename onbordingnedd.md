Yes, everything you described is possible for a developer to build using your existing HexaOne PHP + MySQL employee portal, React frontend, and Moodle LMS. You do not need to rebuild the Timesheet system.

The key is to complete the integration in stages: first connect employee identities, then SSO, then course tracking, and finally display learning reports in HexaOne.

The complete development process
Phase 1 — Confirm the existing system
Confirm HexaOne's existing PHP login works and identifies the logged-in employee.
Confirm the employee database contains a unique ID, company email and active status.
Confirm you have authorized Moodle administrator access and can configure authentication and web services.
Phase 2 — Connect employee accounts

Create a database mapping in your HexaOne backend, for example:

Field

	

Example




Employee ID

	

HL198




Moodle user ID

	

245




Sync status

	

Linked

The Moodle user ID above is only an example. The real ID must come from Moodle. Ensure the same employee isn't accidentally created twice.

Phase 3 — Configure SSO

Choose a Moodle-compatible SSO method that works with your existing login. The developer and Moodle administrator configure and test it together.

Expected result: Ashis logs into HexaOne, clicks Learning Roadmap, and Moodle recognizes Ashis without asking for another login.

Phase 4 — Configure Moodle API

The Moodle administrator enables web services, enables REST, creates a restricted external service, and authorizes only the required functions and account. Your PHP backend uses the service token securely. Follow the 
official Moodle web services guide
. 
MoodleDocs
+1

Phase 5 — Sync course progress

Your PHP backend retrieves the relevant Moodle records and exposes a HexaOne API for the React frontend.

The employee dashboard can show:

Courses completed out of 10

Current course and progress

Score and pass/fail result, if reported

Completion date and attempts, where available

Final assessment availability

Your admin dashboard can show these records across employees, subject to permissions.

Phase 6 — Configure the 10-course roadmap

Upload the remaining SCORM packages, set course completion requirements and configure the final assessment to unlock only after all 10 courses are complete. Test the restrictions in Moodle itself, not just in the React UI.

Phase 7 — Test before releasing

Developer test checklist

0 of 8

Employee login works without a second Moodle login
Correct employee is linked to the correct Moodle account
SCORM progress and completion are saved in Moodle
HexaOne shows the correct employee's progress
Admin can see all authorized employees' progress
Final assessment stays locked until all 10 courses are complete
Inactive employees cannot continue accessing restricted learning
API tokens are never exposed to the browser
What you should do today

Don't start coding every part at once. First confirm the existing login and Moodle admin access. Then build and test one employee's account mapping and one course launch before expanding to all employees and all 10 courses.

One important limitation: Moodle's available API functions and SCORM tracking fields depend on your Moodle version, permissions and package settings. Verify these on your actual site before finalizing the code.


Yes, you're on the correct Moodle page: Site administration → Web services → Manage protocols.

You can see REST protocol and SOAP protocol. For your HexaOne integration, we will use REST.

Do these steps now

Find the Enable column for REST protocol.

If REST has an enable/disable control, make sure REST is enabled.

Leave SOAP disabled unless another integration requires it.

Leave web services documentation disabled for now.

Click Save changes if you changed anything.

What comes next?

After REST is enabled, we need to configure:

Enable web services in Moodle's Advanced features.

Create an external service with only the required permissions.

Create a restricted service account and token.

Connect your HexaOne PHP backend to Moodle.

Configure SSO separately so employees can open courses without another login.

Please tell me one thing: Is REST already enabled on this page, or does it show a button to enable it?
Yes! You can connect all 50 HexaOne employees to Moodle automatically. You do not need to create 50 Moodle accounts manually.

The developer can build an employee synchronization process in your existing PHP + MySQL backend.

How it will work for 50 employees

HexaOne employee database

50 employees

Employee ID · Name · Company email · Active status

PHP synchronization service

Find existing Moodle accounts, create missing accounts, and save the employee-to-Moodle mapping.

Moodle accounts

50 linked accounts

Each employee has one account and access to assigned onboarding courses.

Example

HexaOne employee

	

Moodle account




HL198 — Ashis

	

Moodle user ID 245




HL199 — Employee B

	

Moodle user ID 246




HL200 — Employee C

	

Moodle user ID 247




...

	

...




50 employees

	

50 linked accounts

The Moodle IDs are illustrative, not actual IDs from your site.

The system stores the mapping, so it doesn't create another account every time an employee logs in.

What happens when an employee logs in?

The employee logs in to HexaOne as usual.

They click Learning Roadmap.

SSO authenticates them with their corresponding Moodle account.

They open their assigned course without a second login.

Moodle records their SCORM progress.

HexaOne retrieves their learning records and displays them in the employee dashboard. The admin dashboard can display all authorized employees' progress.

What the developer needs to build

Account sync: Create or link Moodle accounts for existing employees.

Login sync (SSO): Connect the current HexaOne login to Moodle authentication.

Learning data sync: Retrieve Moodle completion, scores and attempts where available.

Admin reporting: Display progress for all employees.

Ongoing sync: Handle newly joined employees and changes to employee status.

Your REST protocol is already enabled, which is a useful first step. We still need to configure Moodle's web services and permissions.

Important: REST API enables backend communication; it does not itself provide SSO. Keep the Moodle service token on your PHP server, never in React.

Your next step

Open Site administration → Advanced features and check whether Enable web services is turned on. Once we confirm that, we can configure the account-sync service for your 50 employees.


<!-- ----------------------------------------- -->
Yes! You can connect all 50 HexaOne employees to Moodle automatically. You do not need to create 50 Moodle accounts manually.

The developer can build an employee synchronization process in your existing PHP + MySQL backend.

How it will work for 50 employees

HexaOne employee database

50 employees

Employee ID · Name · Company email · Active status

PHP synchronization service

Find existing Moodle accounts, create missing accounts, and save the employee-to-Moodle mapping.

Moodle accounts

50 linked accounts

Each employee has one account and access to assigned onboarding courses.

Example

HexaOne employee

	

Moodle account




HL198 — Ashis

	

Moodle user ID 245




HL199 — Employee B

	

Moodle user ID 246




HL200 — Employee C

	

Moodle user ID 247




...

	

...




50 employees

	

50 linked accounts

The Moodle IDs are illustrative, not actual IDs from your site.

The system stores the mapping, so it doesn't create another account every time an employee logs in.

What happens when an employee logs in?

The employee logs in to HexaOne as usual.

They click Learning Roadmap.

SSO authenticates them with their corresponding Moodle account.

They open their assigned course without a second login.

Moodle records their SCORM progress.

HexaOne retrieves their learning records and displays them in the employee dashboard. The admin dashboard can display all authorized employees' progress.

What the developer needs to build

Account sync: Create or link Moodle accounts for existing employees.

Login sync (SSO): Connect the current HexaOne login to Moodle authentication.

Learning data sync: Retrieve Moodle completion, scores and attempts where available.

Admin reporting: Display progress for all employees.

Ongoing sync: Handle newly joined employees and changes to employee status.

Your REST protocol is already enabled, which is a useful first step. We still need to configure Moodle's web services and permissions.

Important: REST API enables backend communication; it does not itself provide SSO. Keep the Moodle service token on your PHP server, never in React.

Your next step

Open Site administration → Advanced features and check whether Enable web services is turned on. Once we confirm that, we can configure the account-sync service for your 50 employees.