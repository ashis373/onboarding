Add functions to the service "HexaLearn API"
Function	Description	Required capabilities	Edit
core_calendar_get_calendar_events	
Get calendar events
moodle/calendar:manageentries, moodle/calendar:manageownentries, moodle/calendar:managegroupentries
Remove
core_course_get_contents	
Get course contents
moodle/course:update, moodle/course:viewhiddencourses
Remove
core_course_get_courses	
Return course details
moodle/course:view, moodle/course:update, moodle/course:viewhiddencourses
Remove
core_enrol_get_enrolled_users	
Get enrolled users by course id.
moodle/user:viewdetails, moodle/user:viewhiddendetails, moodle/course:useremail, moodle/user:update, moodle/site:accessallgroups
Remove
core_enrol_get_enrolled_users_with_capability	
For each course and capability specified, return a list of the users that are enrolled in the course and have that capability
Remove
core_enrol_get_users_courses	
Get the list of courses where a user is enrolled in
moodle/course:viewparticipants
Remove
core_user_get_users	
search for users matching the parameters
moodle/user:viewdetails, moodle/user:viewhiddendetails, moodle/course:useremail, moodle/user:update
Remove
core_user_get_users_by_field	
Retrieve users' information for a specified unique field - If you want to do a user search, use core_user_get_users() or core_user_search_identity().
moodle/user:viewdetails, moodle/user:viewhiddendetails, moodle/course:useremail, moodle/user:update
Remove
core_webservice_get_site_info	
Return some site info / user info / list web service functions
Remove
gradereport_user_get_grade_items	
Returns the complete list of grade items for users in a course
gradereport/user:view
Remove
mod_assign_get_assignments	
Returns the courses and assignments for the users capability
Remove
mod_assign_get_submission_status	
Returns information about an assignment submission status for a given user.
mod/assign:view
Remove
mod_quiz_get_quizzes_by_courses	
Returns a list of quizzes in a provided list of courses, if no list is provided all quizzes that the user can view will be returned.
mod/quiz:view
Remove
mod_scorm_get_scorm_attempt_count	
Return the number of attempts done by a user in the given SCORM.
Remove
mod_scorm_get_scorm_sco_tracks	
Retrieves SCO tracking data for the given user id and attempt number
Remove
mod_scorm_get_scorm_scoes	
Returns a list containing all the scoes data related to the given scorm id
Remove
mod_scorm_get_scorm_user_data	
Retrieves user tracking and SCO data and default SCORM values
Remove
mod_scorm_get_scorms_by_courses	
Returns a list of scorm instances in a provided set of courses, if no courses are provided then all the scorm instances the user has access to will be returned.
Remove
Add functions