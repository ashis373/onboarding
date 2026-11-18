# Strict Database Rules for Onboarding System

### 1. Authorized Modification Tables (`onboard_*` Only)
All writes, updates, insertions, progress tracking, attempt histories, session telemetry, user enrollments, and configuration are strictly confined to tables starting with the **`onboard_`** prefix:

- `onboard_modules`
- `onboard_moodle_users`
- `onboard_module_progress`
- `onboard_module_sessions`
- `onboard_assessment_attempts`
- `onboard_settings`
- Any future/additional table MUST start with the **`onboard_`** prefix (e.g. `onboard_*`).

### 2. Master Table Protection (`user` Table)
The **`user`** table is a shared company master table and is strictly **READ-ONLY**:
- **READ-ONLY (`SELECT` ONLY):** Used exclusively to read employee identity, name, email, profile photo, and active status (`status = 0`).
- **PROHIBITED:** Never execute `UPDATE`, `INSERT`, `DELETE`, `ALTER`, `DROP`, or modify the schema, indexes, columns, or data of the `user` table.
- **PROHIBITED:** Never modify, alter, or create any table that does NOT begin with `onboard_`.