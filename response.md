
    "database": "u413479707_timesheet",
    "total_onboard_tables": 20,
    "tables": {
        "onboard_assessments": [
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
                "column": "description",
                "type": "text",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "time_limit_minutes",
                "type": "int(11)",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "is_active",
                "type": "tinyint(1)",
                "nullable": "NO",
                "key": "",
                "default": "1",
                "extra": ""
            },
            {
                "column": "created_at",
                "type": "timestamp",
                "nullable": "YES",
                "key": "",
                "default": "current_timestamp()",
                "extra": ""
            },
            {
                "column": "updated_at",
                "type": "timestamp",
                "nullable": "YES",
                "key": "",
                "default": "current_timestamp()",
                "extra": "on update current_timestamp()"
            },
            {
                "column": "attempt_limit",
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
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "randomize_questions",
                "type": "tinyint(1)",
                "nullable": "NO",
                "key": "",
                "default": "1",
                "extra": ""
            },
            {
                "column": "position",
                "type": "int(11)",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            }
        ],
        "onboard_assessment_answers": [
            {
                "column": "id",
                "type": "bigint(20)",
                "nullable": "NO",
                "key": "PRI",
                "default": null,
                "extra": "auto_increment"
            },
            {
                "column": "attempt_id",
                "type": "int(11)",
                "nullable": "NO",
                "key": "MUL",
                "default": null,
                "extra": ""
            },
            {
                "column": "question_id",
                "type": "int(11)",
                "nullable": "NO",
                "key": "MUL",
                "default": null,
                "extra": ""
            },
            {
                "column": "answer_text",
                "type": "text",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "selected_option_ids",
                "type": "longtext",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "matching_pairs",
                "type": "longtext",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "reorder_order",
                "type": "longtext",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "is_correct",
                "type": "tinyint(1)",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "score",
                "type": "int(11)",
                "nullable": "NO",
                "key": "",
                "default": "0",
                "extra": ""
            },
            {
                "column": "max_score",
                "type": "int(11)",
                "nullable": "NO",
                "key": "",
                "default": "1",
                "extra": ""
            },
            {
                "column": "position",
                "type": "int(11)",
                "nullable": "NO",
                "key": "",
                "default": "0",
                "extra": ""
            }
        ],
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
                "key": "MUL",
                "default": null,
                "extra": ""
            },
            {
                "column": "user_id",
                "type": "int(11)",
                "nullable": "NO",
                "key": "",
                "default": null,
                "extra": ""
            },
            {
                "column": "score",
                "type": "int(11)",
                "nullable": "NO",
                "key": "",
                "default": "0",
                "extra": ""
            },
            {
                "column": "max_score",
                "type": "int(11)",
                "nullable": "NO",
                "key": "",
                "default": "0",
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
                "column": "started_at",
                "type": "datetime",
                "nullable": "NO",
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
                "column": "is_draft",
                "type": "tinyint(1)",
                "nullable": "NO",
                "key": "",
                "default": "0",
                "extra": ""
            }
        ],
        "onboard_assessment_pools": [
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
                "key": "MUL",
                "default": null,
                "extra": ""
            },
            {
                "column": "pool_id",
                "type": "int(11)",
                "nullable": "NO",
                "key": "MUL",
                "default": null,
                "extra": ""
            },
            {
                "column": "questions_to_draw",
                "type": "int(11)",
                "nullable": "NO",
                "key": "",
                "default": null,
                "extra": ""
            },
            {
                "column": "position",
                "type": "int(11)",
                "nullable": "NO",
                "key": "",
                "default": "0",
                "extra": ""
            }
        ],
        "onboard_assessment_questions": [
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
                "key": "MUL",
                "default": null,
                "extra": ""
            },
            {
                "column": "question",
                "type": "text",
                "nullable": "NO",
                "key": "",
                "default": null,
                "extra": ""
            },
            {
                "column": "option_a",
                "type": "varchar(255)",
                "nullable": "NO",
                "key": "",
                "default": null,
                "extra": ""
            },
            {
                "column": "option_b",
                "type": "varchar(255)",
                "nullable": "NO",
                "key": "",
                "default": null,
                "extra": ""
            },
            {
                "column": "option_c",
                "type": "varchar(255)",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "option_d",
                "type": "varchar(255)",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "correct_option",
                "type": "enum('A','B','C','D')",
                "nullable": "NO",
                "key": "",
                "default": null,
                "extra": ""
            }
        ],
        "onboard_buddies": [
            {
                "column": "id",
                "type": "int(11)",
                "nullable": "NO",
                "key": "PRI",
                "default": null,
                "extra": "auto_increment"
            },
            {
                "column": "employee_id",
                "type": "int(11)",
                "nullable": "NO",
                "key": "UNI",
                "default": null,
                "extra": ""
            },
            {
                "column": "buddy_id",
                "type": "int(11)",
                "nullable": "NO",
                "key": "",
                "default": null,
                "extra": ""
            },
            {
                "column": "assigned_at",
                "type": "datetime",
                "nullable": "YES",
                "key": "",
                "default": "current_timestamp()",
                "extra": ""
            },
            {
                "column": "assigned_by",
                "type": "int(11)",
                "nullable": "NO",
                "key": "",
                "default": null,
                "extra": ""
            }
        ],
        "onboard_feedback_pulse": [
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
                "column": "sentiment",
                "type": "tinyint(1)",
                "nullable": "NO",
                "key": "",
                "default": null,
                "extra": ""
            },
            {
                "column": "note",
                "type": "text",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "submitted_at",
                "type": "datetime",
                "nullable": "YES",
                "key": "MUL",
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
                "type": "enum('video','pdf','document')",
                "nullable": "NO",
                "key": "",
                "default": "'video'",
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
                "column": "duration_minutes",
                "type": "int(11)",
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
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "designation_id",
                "type": "int(11)",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "is_active",
                "type": "tinyint(1)",
                "nullable": "NO",
                "key": "",
                "default": "1",
                "extra": ""
            },
            {
                "column": "created_at",
                "type": "timestamp",
                "nullable": "YES",
                "key": "",
                "default": "current_timestamp()",
                "extra": ""
            },
            {
                "column": "updated_at",
                "type": "timestamp",
                "nullable": "YES",
                "key": "",
                "default": "current_timestamp()",
                "extra": "on update current_timestamp()"
            },
            {
                "column": "roadmap_day",
                "type": "int(11)",
                "nullable": "NO",
                "key": "",
                "default": "1",
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
                "key": "MUL",
                "default": null,
                "extra": ""
            },
            {
                "column": "is_completed",
                "type": "tinyint(1)",
                "nullable": "NO",
                "key": "",
                "default": "0",
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
                "column": "started_at",
                "type": "datetime",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "last_viewed_at",
                "type": "datetime",
                "nullable": "YES",
                "key": "MUL",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "progress_pct",
                "type": "tinyint(3) unsigned",
                "nullable": "YES",
                "key": "",
                "default": "0",
                "extra": ""
            },
            {
                "column": "time_spent_seconds",
                "type": "int(10) unsigned",
                "nullable": "YES",
                "key": "",
                "default": "0",
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
                "nullable": "YES",
                "key": "",
                "default": "current_timestamp()",
                "extra": ""
            },
            {
                "column": "updated_at",
                "type": "timestamp",
                "nullable": "YES",
                "key": "",
                "default": "current_timestamp()",
                "extra": "on update current_timestamp()"
            }
        ],
        "onboard_pools": [
            {
                "column": "id",
                "type": "int(11)",
                "nullable": "NO",
                "key": "PRI",
                "default": null,
                "extra": "auto_increment"
            },
            {
                "column": "name",
                "type": "varchar(255)",
                "nullable": "NO",
                "key": "",
                "default": null,
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
                "column": "is_active",
                "type": "tinyint(1)",
                "nullable": "NO",
                "key": "",
                "default": "1",
                "extra": ""
            },
            {
                "column": "created_at",
                "type": "timestamp",
                "nullable": "YES",
                "key": "",
                "default": "current_timestamp()",
                "extra": ""
            },
            {
                "column": "updated_at",
                "type": "timestamp",
                "nullable": "YES",
                "key": "",
                "default": "current_timestamp()",
                "extra": "on update current_timestamp()"
            }
        ],
        "onboard_pool_questions": [
            {
                "column": "id",
                "type": "int(11)",
                "nullable": "NO",
                "key": "PRI",
                "default": null,
                "extra": "auto_increment"
            },
            {
                "column": "pool_id",
                "type": "int(11)",
                "nullable": "NO",
                "key": "MUL",
                "default": null,
                "extra": ""
            },
            {
                "column": "question_id",
                "type": "int(11)",
                "nullable": "NO",
                "key": "MUL",
                "default": null,
                "extra": ""
            },
            {
                "column": "weight",
                "type": "int(11)",
                "nullable": "NO",
                "key": "",
                "default": "1",
                "extra": ""
            }
        ],
        "onboard_questions": [
            {
                "column": "id",
                "type": "int(11)",
                "nullable": "NO",
                "key": "PRI",
                "default": null,
                "extra": "auto_increment"
            },
            {
                "column": "type_code",
                "type": "varchar(32)",
                "nullable": "NO",
                "key": "MUL",
                "default": null,
                "extra": ""
            },
            {
                "column": "prompt",
                "type": "text",
                "nullable": "NO",
                "key": "",
                "default": null,
                "extra": ""
            },
            {
                "column": "explanation",
                "type": "text",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "tags",
                "type": "longtext",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "is_active",
                "type": "tinyint(1)",
                "nullable": "NO",
                "key": "",
                "default": "1",
                "extra": ""
            },
            {
                "column": "created_at",
                "type": "timestamp",
                "nullable": "YES",
                "key": "",
                "default": "current_timestamp()",
                "extra": ""
            },
            {
                "column": "updated_at",
                "type": "timestamp",
                "nullable": "YES",
                "key": "",
                "default": "current_timestamp()",
                "extra": "on update current_timestamp()"
            }
        ],
        "onboard_question_matching_pairs": [
            {
                "column": "id",
                "type": "int(11)",
                "nullable": "NO",
                "key": "PRI",
                "default": null,
                "extra": "auto_increment"
            },
            {
                "column": "question_id",
                "type": "int(11)",
                "nullable": "NO",
                "key": "MUL",
                "default": null,
                "extra": ""
            },
            {
                "column": "left_text",
                "type": "varchar(255)",
                "nullable": "NO",
                "key": "",
                "default": null,
                "extra": ""
            },
            {
                "column": "right_text",
                "type": "varchar(255)",
                "nullable": "NO",
                "key": "",
                "default": null,
                "extra": ""
            },
            {
                "column": "position",
                "type": "int(11)",
                "nullable": "NO",
                "key": "",
                "default": "0",
                "extra": ""
            }
        ],
        "onboard_question_options": [
            {
                "column": "id",
                "type": "int(11)",
                "nullable": "NO",
                "key": "PRI",
                "default": null,
                "extra": "auto_increment"
            },
            {
                "column": "question_id",
                "type": "int(11)",
                "nullable": "NO",
                "key": "MUL",
                "default": null,
                "extra": ""
            },
            {
                "column": "label",
                "type": "text",
                "nullable": "NO",
                "key": "",
                "default": null,
                "extra": ""
            },
            {
                "column": "is_correct",
                "type": "tinyint(1)",
                "nullable": "NO",
                "key": "",
                "default": "0",
                "extra": ""
            },
            {
                "column": "position",
                "type": "int(11)",
                "nullable": "NO",
                "key": "",
                "default": "0",
                "extra": ""
            }
        ],
        "onboard_question_reorder_items": [
            {
                "column": "id",
                "type": "int(11)",
                "nullable": "NO",
                "key": "PRI",
                "default": null,
                "extra": "auto_increment"
            },
            {
                "column": "question_id",
                "type": "int(11)",
                "nullable": "NO",
                "key": "MUL",
                "default": null,
                "extra": ""
            },
            {
                "column": "item_text",
                "type": "varchar(255)",
                "nullable": "NO",
                "key": "",
                "default": null,
                "extra": ""
            },
            {
                "column": "position",
                "type": "int(11)",
                "nullable": "NO",
                "key": "",
                "default": "0",
                "extra": ""
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
        "onboard_results_export": [
            {
                "column": "attempt_id",
                "type": "int(11)",
                "nullable": "NO",
                "key": "",
                "default": "0",
                "extra": ""
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
                "key": "",
                "default": null,
                "extra": ""
            },
            {
                "column": "started_at",
                "type": "datetime",
                "nullable": "NO",
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
                "column": "score",
                "type": "int(11)",
                "nullable": "NO",
                "key": "",
                "default": "0",
                "extra": ""
            },
            {
                "column": "max_score",
                "type": "int(11)",
                "nullable": "NO",
                "key": "",
                "default": "0",
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
                "column": "question_id",
                "type": "int(11)",
                "nullable": "NO",
                "key": "",
                "default": "0",
                "extra": ""
            },
            {
                "column": "type_code",
                "type": "varchar(32)",
                "nullable": "NO",
                "key": "",
                "default": null,
                "extra": ""
            },
            {
                "column": "prompt",
                "type": "text",
                "nullable": "NO",
                "key": "",
                "default": null,
                "extra": ""
            },
            {
                "column": "answer_text",
                "type": "text",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "selected_option_ids",
                "type": "longtext",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "matching_pairs",
                "type": "longtext",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "reorder_order",
                "type": "longtext",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "is_correct",
                "type": "tinyint(1)",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "answer_score",
                "type": "int(11)",
                "nullable": "NO",
                "key": "",
                "default": "0",
                "extra": ""
            },
            {
                "column": "answer_max_score",
                "type": "int(11)",
                "nullable": "NO",
                "key": "",
                "default": "1",
                "extra": ""
            },
            {
                "column": "position",
                "type": "int(11)",
                "nullable": "NO",
                "key": "",
                "default": "0",
                "extra": ""
            }
        ],
        "onboard_saved_filters": [
            {
                "column": "id",
                "type": "int(11)",
                "nullable": "NO",
                "key": "PRI",
                "default": null,
                "extra": "auto_increment"
            },
            {
                "column": "name",
                "type": "varchar(255)",
                "nullable": "NO",
                "key": "",
                "default": null,
                "extra": ""
            },
            {
                "column": "owner_id",
                "type": "int(11)",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            },
            {
                "column": "kind",
                "type": "enum('analytics','search')",
                "nullable": "NO",
                "key": "",
                "default": null,
                "extra": ""
            },
            {
                "column": "payload",
                "type": "longtext",
                "nullable": "NO",
                "key": "",
                "default": null,
                "extra": ""
            },
            {
                "column": "created_at",
                "type": "timestamp",
                "nullable": "YES",
                "key": "",
                "default": "current_timestamp()",
                "extra": ""
            }
        ],
        "onboard_settings": [
            {
                "column": "key",
                "type": "varchar(128)",
                "nullable": "NO",
                "key": "PRI",
                "default": null,
                "extra": ""
            },
            {
                "column": "value",
                "type": "text",
                "nullable": "YES",
                "key": "",
                "default": "NULL",
                "extra": ""
            }
        ]
    }
}