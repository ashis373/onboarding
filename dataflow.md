[Employee launches SCORM Course]
             │
             ▼
[Articulate Storyline Player]
             │ (LMSSetValue & LMSCommit)
             ▼
[Moodle LMS: mdl_scorm_scoes_track]  <── (Master Telemetry Store)
             │
             │ (Moodle REST API: mod_scorm_get_scorm_sco_tracks)
             ▼
[HexaLearn Backend: api/moodle/courses.php & api/admin/employee_progress.php]
             │
             ├─► Syncs into Local MySQL: [onboard_module_progress]
             │
             ▼
[Rendered in Employee Portal & Admin Employee Report]
