{
    "database": "hex-timesheet",
    "total_onboard_tables": 7,
    "tables": {
        "onboard_assessment_attempts": [
            {
                "column": "id",
                "type": "int(11)",
                "nullable": "NO",
                "key": "PRI",
                "default": null,
                "extra": "auto_increment"
            },
            {
                "column": "assessment_id",
                "type": "int(11)",
                "nullable": "NO",
                "key": "",
                "default": null,
                "extra": ""
            },
            {
                "column": "user_id",
                "type": "int(11)",
                "nullable": "NO",
                "key": "MUL",
                "default": null,
                "extra": ""
            },
            {
                "column": "score",
                "type": "decimal(5,2)",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "started_at",
                "type": "datetime",
                "nullable": "YES",
                "key": "",
                "default": "current_timestamp()",
                "extra": ""
            },
            {
                "column": "submitted_at",
                "type": "datetime",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "max_score",
                "type": "int(11)",
                "nullable": "YES",
                "key": "",
                "default": "100",
                "extra": ""
            },
            {
                "column": "is_passed",
                "type": "tinyint(1)",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "module_id",
                "type": "int(11)",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "attempt_number",
                "type": "int(11)",
                "nullable": "NO",
                "key": "",
                "default": "1",
                "extra": ""
            },
            {
                "column": "result",
                "type": "enum('Passed','Failed','Incomplete')",
                "nullable": "YES",
                "key": "",
                "default": "'Incomplete'",
                "extra": ""
            },
            {
                "column": "total_time",
                "type": "varchar(32)",
                "nullable": "YES",
                "key": "",
                "default": "'00:00:00'",
                "extra": ""
            },
            {
                "column": "total_time_seconds",
                "type": "int(10) unsigned",
                "nullable": "YES",
                "key": "",
                "default": "0",
                "extra": ""
            },
            {
                "column": "attempted_at",
                "type": "datetime",
                "nullable": "YES",
                "key": "",
                "default": "current_timestamp()",
                "extra": ""
            }
        ],
        "onboard_modules": [
            {
                "column": "id",
                "type": "int(11)",
                "nullable": "NO",
                "key": "PRI",
                "default": null,
                "extra": "auto_increment"
            },
            {
                "column": "title",
                "type": "varchar(255)",
                "nullable": "NO",
                "key": "",
                "default": null,
                "extra": ""
            },
            {
                "column": "type",
                "type": "enum('scorm','assessment','video','pdf','document')",
                "nullable": "YES",
                "key": "",
                "default": "'scorm'",
                "extra": ""
            },
            {
                "column": "description",
                "type": "text",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "duration_minutes",
                "type": "int(11)",
                "nullable": "YES",
                "key": "",
                "default": "0",
                "extra": ""
            },
            {
                "column": "video_url",
                "type": "varchar(512)",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "file_url",
                "type": "varchar(512)",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "department_id",
                "type": "int(11)",
                "nullable": "YES",
                "key": "",
                "default": "0",
                "extra": ""
            },
            {
                "column": "designation_id",
                "type": "int(11)",
                "nullable": "YES",
                "key": "",
                "default": "0",
                "extra": ""
            },
            {
                "column": "roadmap_day",
                "type": "int(11)",
                "nullable": "NO",
                "key": "",
                "default": "1",
                "extra": ""
            },
            {
                "column": "is_active",
                "type": "tinyint(1)",
                "nullable": "YES",
                "key": "",
                "default": "1",
                "extra": ""
            },
            {
                "column": "sort_order",
                "type": "int(11)",
                "nullable": "YES",
                "key": "",
                "default": "0",
                "extra": ""
            },
            {
                "column": "created_at",
                "type": "datetime",
                "nullable": "YES",
                "key": "",
                "default": "current_timestamp()",
                "extra": ""
            },
            {
                "column": "moodle_course_id",
                "type": "int(11)",
                "nullable": "YES",
                "key": "MUL",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "moodle_cmid",
                "type": "int(11)",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "moodle_scoid",
                "type": "int(11)",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "passing_score",
                "type": "int(11)",
                "nullable": "YES",
                "key": "",
                "default": "80",
                "extra": ""
            }
        ],
        "onboard_module_progress": [
            {
                "column": "id",
                "type": "int(11)",
                "nullable": "NO",
                "key": "PRI",
                "default": null,
                "extra": "auto_increment"
            },
            {
                "column": "user_id",
                "type": "int(11)",
                "nullable": "NO",
                "key": "MUL",
                "default": null,
                "extra": ""
            },
            {
                "column": "module_id",
                "type": "int(11)",
                "nullable": "NO",
                "key": "",
                "default": null,
                "extra": ""
            },
            {
                "column": "started_at",
                "type": "datetime",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "completed_at",
                "type": "datetime",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "is_completed",
                "type": "tinyint(1)",
                "nullable": "YES",
                "key": "",
                "default": "0",
                "extra": ""
            },
            {
                "column": "status",
                "type": "enum('not_started','in_progress','completed','failed')",
                "nullable": "YES",
                "key": "",
                "default": "'not_started'",
                "extra": ""
            },
            {
                "column": "progress_percent",
                "type": "int(11)",
                "nullable": "YES",
                "key": "",
                "default": "0",
                "extra": ""
            },
            {
                "column": "highest_progress_percent",
                "type": "int(11)",
                "nullable": "YES",
                "key": "",
                "default": "0",
                "extra": ""
            },
            {
                "column": "score",
                "type": "decimal(5,2)",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "highest_score",
                "type": "decimal(5,2)",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "passing_score",
                "type": "int(11)",
                "nullable": "YES",
                "key": "",
                "default": "80",
                "extra": ""
            },
            {
                "column": "total_time",
                "type": "varchar(32)",
                "nullable": "YES",
                "key": "",
                "default": "'00:00:00'",
                "extra": ""
            },
            {
                "column": "total_time_seconds",
                "type": "int(10) unsigned",
                "nullable": "YES",
                "key": "",
                "default": "0",
                "extra": ""
            },
            {
                "column": "attempts",
                "type": "int(11)",
                "nullable": "YES",
                "key": "",
                "default": "0",
                "extra": ""
            },
            {
                "column": "suspend_data",
                "type": "text",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "lesson_location",
                "type": "varchar(255)",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "slides_viewed",
                "type": "int(10) unsigned",
                "nullable": "YES",
                "key": "",
                "default": "0",
                "extra": ""
            },
            {
                "column": "total_slides",
                "type": "int(10) unsigned",
                "nullable": "YES",
                "key": "",
                "default": "0",
                "extra": ""
            },
            {
                "column": "last_checked_at",
                "type": "datetime",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "last_synced_at",
                "type": "datetime",
                "nullable": "YES",
                "key": "",
                "default": "current_timestamp()",
                "extra": "on update current_timestamp()"
            }
        ],
        "onboard_module_sessions": [
            {
                "column": "id",
                "type": "int(11)",
                "nullable": "NO",
                "key": "PRI",
                "default": null,
                "extra": "auto_increment"
            },
            {
                "column": "user_id",
                "type": "int(11)",
                "nullable": "NO",
                "key": "MUL",
                "default": null,
                "extra": ""
            },
            {
                "column": "module_id",
                "type": "int(11)",
                "nullable": "NO",
                "key": "",
                "default": null,
                "extra": ""
            },
            {
                "column": "session_time",
                "type": "varchar(32)",
                "nullable": "YES",
                "key": "",
                "default": "'00:00:00'",
                "extra": ""
            },
            {
                "column": "session_seconds",
                "type": "int(10) unsigned",
                "nullable": "YES",
                "key": "",
                "default": "0",
                "extra": ""
            },
            {
                "column": "slides_viewed",
                "type": "int(10) unsigned",
                "nullable": "YES",
                "key": "",
                "default": "0",
                "extra": ""
            },
            {
                "column": "progress_percent",
                "type": "int(11)",
                "nullable": "YES",
                "key": "",
                "default": "0",
                "extra": ""
            },
            {
                "column": "score",
                "type": "decimal(5,2)",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "status",
                "type": "varchar(32)",
                "nullable": "YES",
                "key": "",
                "default": "'in_progress'",
                "extra": ""
            },
            {
                "column": "lesson_location",
                "type": "varchar(255)",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "suspend_data",
                "type": "text",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "recorded_at",
                "type": "datetime",
                "nullable": "YES",
                "key": "",
                "default": "current_timestamp()",
                "extra": ""
            }
        ],
        "onboard_moodle_users": [
            {
                "column": "id",
                "type": "int(11)",
                "nullable": "NO",
                "key": "PRI",
                "default": null,
                "extra": "auto_increment"
            },
            {
                "column": "hexa_uid",
                "type": "int(11)",
                "nullable": "NO",
                "key": "UNI",
                "default": null,
                "extra": ""
            },
            {
                "column": "username",
                "type": "varchar(100)",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "moodle_user_id",
                "type": "int(11)",
                "nullable": "YES",
                "key": "UNI",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "sync_status",
                "type": "enum('Not linked','Pending verification','Linked','Error')",
                "nullable": "YES",
                "key": "",
                "default": "'Not linked'",
                "extra": ""
            },
            {
                "column": "last_synced_at",
                "type": "datetime",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "notes",
                "type": "varchar(255)",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "created_at",
                "type": "timestamp",
                "nullable": "NO",
                "key": "",
                "default": "current_timestamp()",
                "extra": ""
            },
            {
                "column": "updated_at",
                "type": "timestamp",
                "nullable": "NO",
                "key": "",
                "default": "current_timestamp()",
                "extra": "on update current_timestamp()"
            }
        ],
        "onboard_question_types": [
            {
                "column": "id",
                "type": "tinyint(3) unsigned",
                "nullable": "NO",
                "key": "PRI",
                "default": null,
                "extra": "auto_increment"
            },
            {
                "column": "code",
                "type": "varchar(32)",
                "nullable": "NO",
                "key": "UNI",
                "default": null,
                "extra": ""
            },
            {
                "column": "label",
                "type": "varchar(64)",
                "nullable": "NO",
                "key": "",
                "default": null,
                "extra": ""
            }
        ],
        "onboard_settings": [
            {
                "column": "key",
                "type": "varchar(191)",
                "nullable": "NO",
                "key": "PRI",
                "default": null,
                "extra": ""
            },
            {
                "column": "value",
                "type": "longtext",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "updated_at",
                "type": "timestamp",
                "nullable": "NO",
                "key": "",
                "default": "current_timestamp()",
                "extra": "on update current_timestamp()"
            }
        ]
    }
}