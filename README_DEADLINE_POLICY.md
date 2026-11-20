# HexaLearn Onboarding — Deadline & Access Control Policy
## Complete Implementation Guide & Technical Specification

---

## 📌 Executive Summary

This document defines the architecture, rules, database schema, API specifications, and frontend workflow for the **Onboarding Deadline, Access Lockout, and Individual Manager Extension** system.

### Core Objectives:
1. **Protect Completed Employees:** Any employee who successfully passes the assessment (in **Attempt 1 OR Attempt 2**, such as *Saroj Kumar Das HL203*) is **permanently certified** and **NEVER locked**.
2. **Lock Incomplete Employees After Deadline:** Employees who have not passed and failed to complete their attempts before the cutoff date are locked out from accessing modules and assessments.
3. **Individual Manager Extension:** A manager or administrator can grant an extension to an individual employee (e.g., extend to Nov 29). **Only that employee is unlocked**; all other incomplete employees remain locked.
4. **Dynamic 7 Working Days for New Joiners:** New joiners entering the portal on or after November 25 receive exactly 7 official working days (skipping Sundays, 2nd & 3rd Saturdays, and official company holidays).

---

## 🎯 Completion & Lockout Rules Matrix

| Case | Scenario | Assessment History | On Deadline Expired | Portal & Module Access |
| :--- | :--- | :--- | :--- | :--- |
| **Case 1** | Passed on Attempt 1 | Attempt 1 = **Passed** (≥ 80%) | 🟢 **Certified & Completed** | ✅ **Full Lifetime Access** (Never Locked) |
| **Case 2** | Passed on Attempt 2 *(e.g. Saroj Kumar Das)* | Attempt 1 = Failed, Attempt 2 = **Passed** (100%) | 🟢 **Certified & Completed** | ✅ **Full Lifetime Access** (Never Locked) |
| **Case 3** | Failed Both Attempts | Attempt 1 = Failed, Attempt 2 = Failed | 🔴 **Assessment Finished / Failed** | 🔒 Quiz locked by 2-attempt rule (Retakes exhausted) |
| **Case 4** | Never Started | **0 Attempts** | ⛔ **Incomplete (Expired)** | 🔒 **FULL LOCKOUT** (Modules & Assessment blocked) |
| **Case 5** | Unfinished Retake | Attempt 1 = Failed, **Attempt 2 not taken** | ⛔ **Incomplete (Expired)** | 🔒 **FULL LOCKOUT** (Modules & Assessment blocked) |

> [!IMPORTANT]
> **Key Verification Logic:**
> If an employee has at least one record in `onboard_induction_assessment_attempts` with `result = 'Passed'`, they are **Certified & Completed**. They bypass all deadline locks forever.

---

## 👥 Cohorts & Deadlines

### Batch 1: Existing Employees (50 Employees in `onboard_moodle_users`)
* **Eligibility:** All users currently registered in `onboard_moodle_users` (created on or before November 24, 2026).
* **Hard Cutoff Date:** **November 24, 2026 at 23:59:59 IST**.
* **Lockout Date:** **November 25, 2026 at 00:00:00 IST**.
* **Action on Nov 25:** All employees falling under **Case 4** or **Case 5** are locked. Completed employees (**Case 1 & Case 2**) remain unlocked.

---

### Batch 2: New Employees (Joined on or after Nov 25, 2026)
* **Eligibility:** Users with `onboard_moodle_users.created_at >= '2026-11-25 00:00:00'` (or first portal launch date).
* **Policy:** Exactly **7 Official Working Days**.
* **Calendar Exclusions:**
  1. ❌ **Sundays:** Every Sunday is excluded.
  2. ❌ **2nd & 3rd Saturdays:** The 2nd and 3rd Saturday of any Gregorian calendar month are company off-days and excluded.
  3. ❌ **Company Holidays:** Any date present in the `holiday_info` table is excluded.
* **Calculation:** The start day counts as Day 1 if it is an official working day. The deadline is set to **23:59:59 IST of the 7th working day**.

---

## 🛡 Manager Individual Extension Workflow

```
[Employee Incomplete on Nov 25] ──► [Portal Shows Full-Screen Lockout Modal]
                                             │
                     Employee contacts Reporting Manager / HR
                                             │
                                             ▼
                 Admin / Manager opens Admin Portal (/admin/employees)
                                             │
             Clicks "Extend Deadline" on Incomplete Employee (e.g. Employee A)
                                             │
             Selects New Date (e.g., Nov 29, 2026) & Enters Approval Reason
                                             │
                                             ▼
                         Database updates extended_deadline
                                             │
        ┌────────────────────────────────────┴────────────────────────────────────┐
        ▼                                                                         ▼
[Employee A is UNLOCKED until Nov 29 23:59:59]               [All other incomplete employees]
Can finish modules & attempt retake quiz                     [REMAIN LOCKED]
```

---

## 🗄 Database Architecture & SQL Schema

### 1. New Table: `onboard_employee_deadlines`

```sql
CREATE TABLE IF NOT EXISTS `onboard_employee_deadlines` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL UNIQUE COMMENT 'Foreign key user.uid or hexa_uid',
  `batch_id` TINYINT NOT NULL DEFAULT 1 COMMENT '1 = Existing Batch 1, 2 = New Joiner Batch 2',
  `first_opened_at` DATETIME NULL COMMENT 'First time employee opened the portal',
  `deadline_date` DATETIME NOT NULL COMMENT 'Base deadline (e.g. 2026-11-24 23:59:59)',
  `extended_deadline` DATETIME NULL COMMENT 'Manager approved extension date',
  `extended_by_admin_id` INT NULL COMMENT 'Admin uid who granted extension',
  `extension_reason` VARCHAR(255) NULL COMMENT 'Reason for extension',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_user_deadline` (`user_id`, `deadline_date`),
  INDEX `idx_extended_deadline` (`extended_deadline`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 2. Batch 1 Initial Seeding Query (Existing 50 Users)

```sql
INSERT INTO `onboard_employee_deadlines` (`user_id`, `batch_id`, `deadline_date`, `created_at`)
SELECT 
    mu.hexa_uid AS user_id,
    1 AS batch_id,
    '2026-11-24 23:59:59' AS deadline_date,
    NOW() AS created_at
FROM `onboard_moodle_users` mu
ON DUPLICATE KEY UPDATE 
    `deadline_date` = VALUES(`deadline_date`);
```

---

## ⚙️ Backend Implementation Specifications

### 1. `api/deadline_helper.php` (Core Working Day & Deadline Engine)

#### Working Day Calculation Logic:
```php
<?php
// api/deadline_helper.php

date_default_timezone_set('Asia/Kolkata');

/**
 * Checks if a given date is the 2nd or 3rd Saturday of its month.
 */
function isSecondOrThirdSaturday(DateTime $date): bool {
    if ((int)$date->format('N') !== 6) { // 6 = Saturday
        return false;
    }
    $yearMonth = $date->format('Y-m');
    $secondSat = new DateTime("second saturday of {$yearMonth}");
    $thirdSat  = new DateTime("third saturday of {$yearMonth}");
    
    $currentDateStr = $date->format('Y-m-d');
    return ($currentDateStr === $secondSat->format('Y-m-d') || 
            $currentDateStr === $thirdSat->format('Y-m-d'));
}

/**
 * Checks if a given date is listed in holiday_info table.
 */
function isCompanyHoliday(DateTime $date, mysqli $conn): bool {
    $dateStr = $date->format('Y-m-d');
    $stmt = $conn->prepare("SELECT COUNT(*) FROM holiday_info WHERE date = ?");
    $stmt->bind_param("s", $dateStr);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_row();
    return ((int)$res[0] > 0);
}

/**
 * Calculates exactly 7 working days starting from $startDate.
 * - Skips Sundays (N = 7)
 * - Skips 2nd & 3rd Saturdays
 * - Skips holiday_info dates
 * - Start day is Day 1 if it is an office working day.
 */
function calculate7WorkingDaysDeadline(DateTime $startDate, mysqli $conn): string {
    $current = clone $startDate;
    $workingDaysCounted = 0;
    
    while ($workingDaysCounted < 7) {
        $dayOfWeek = (int)$current->format('N');
        $isSunday = ($dayOfWeek === 7);
        $isOffSaturday = isSecondOrThirdSaturday($current);
        $isHoliday = isCompanyHoliday($current, $conn);
        
        if (!$isSunday && !$isOffSaturday && !$isHoliday) {
            $workingDaysCounted++;
            if ($workingDaysCounted === 7) {
                break;
            }
        }
        $current->modify('+1 day');
    }
    
    return $current->format('Y-m-d') . ' 23:59:59';
}

/**
 * Evaluates the complete deadline status for an employee.
 */
function getEmployeeDeadlineStatus(int $userId, mysqli $conn): array {
    // 1. Check if user already passed assessment
    $stmtPass = $conn->prepare("SELECT COUNT(*) FROM onboard_induction_assessment_attempts WHERE user_id = ? AND result = 'Passed'");
    $stmtPass->bind_param("i", $userId);
    $stmtPass->execute();
    $hasPassed = ((int)$stmtPass->get_result()->fetch_row()[0] > 0);
    
    if ($hasPassed) {
        return [
            'is_deadline_locked' => false,
            'status' => 'completed',
            'is_completed' => true,
            'message' => 'Permanently Certified'
        ];
    }
    
    // 2. Query attempts count
    $stmtAtt = $conn->prepare("SELECT COUNT(*) FROM onboard_induction_assessment_attempts WHERE user_id = ?");
    $stmtAtt->bind_param("i", $userId);
    $stmtAtt->execute();
    $attemptsCount = (int)$stmtAtt->get_result()->fetch_row()[0];
    
    // 3. Get deadline record
    $stmtDl = $conn->prepare("SELECT batch_id, deadline_date, extended_deadline FROM onboard_employee_deadlines WHERE user_id = ?");
    $stmtDl->bind_param("i", $userId);
    $stmtDl->execute();
    $dlRow = $stmtDl->get_result()->fetch_assoc();
    
    $effectiveDeadline = $dlRow['extended_deadline'] ?? ($dlRow['deadline_date'] ?? '2026-11-24 23:59:59');
    $now = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
    $deadlineTime = new DateTime($effectiveDeadline, new DateTimeZone('Asia/Kolkata'));
    
    $isPastDeadline = ($now > $deadlineTime);
    $isLocked = ($isPastDeadline && !$hasPassed);
    
    return [
        'is_deadline_locked' => $isLocked,
        'effective_deadline' => $effectiveDeadline,
        'is_extended' => !empty($dlRow['extended_deadline']),
        'attempts_count' => $attemptsCount,
        'status' => $isLocked ? 'locked' : 'in_progress',
        'is_completed' => false
    ];
}
```

---

### 2. `api/admin/extend_deadline.php` (Admin Extension Endpoint)

```php
<?php
// api/admin/extend_deadline.php
require_once __DIR__ . '/../../moodle/config.php';
header('Content-Type: application/json');

// 1. Verify Admin Session / Role
// 2. Validate inputs: user_id, new_deadline (Y-m-d 23:59:59), reason
// 3. UPDATE onboard_employee_deadlines SET extended_deadline = ?, extended_by_admin_id = ?, extension_reason = ? WHERE user_id = ?
// 4. Invalidate disk cache for this user
// 5. Return success JSON
```

---

### 3. Integrating with `api/moodle/courses.php` & `launch.php`
* In `courses.php`:
  Include `deadline_info` in response:
  ```json
  "deadline_info": {
      "is_deadline_locked": false,
      "status": "completed",
      "effective_deadline": "2026-11-24 23:59:59",
      "is_extended": false
  }
  ```
* In `launch.php`:
  If `is_deadline_locked == true && !has_passed` and user is not admin $\rightarrow$ Return HTTP 403 Forbidden: `{"error": "Onboarding deadline expired. Please contact your manager."}`.

---

## 💻 Frontend Implementation Specifications

### 1. Employee Portal (`src/pages/Dashboard.tsx`)
* Check `deadline_info.is_deadline_locked`.
* If `true` and `!has_passed`:
  - Display **Full-Screen Deadline Lockout View**.
  - Show expired deadline timestamp.
  - Provide a clear callout: *"Your onboarding completion deadline of [Date] has expired. Access to courses and assessments is locked. Please contact your Reporting Manager or HR to request an extension."*
  - Prevent clicking into modules.

### 2. Admin Employee Tracking (`src/pages/AdminEmployees.tsx`)
* Column **Deadline & Access Status**:
  - 🟢 **Completed & Certified** (e.g., Saroj Kumar Das, 100%)
  - 🟡 **In Progress (Active)** (Deadline: 24-Nov-2026)
  - ⏱ **Extended** (Extended to: 29-Nov-2026)
  - 🔒 **Deadline Locked** (Expired on 24-Nov-2026)
* Action: **"Extend Deadline" Button**:
  - Opens modal with:
    - Employee Name & Code
    - Current Deadline
    - New Extended Date Picker (Time auto-fills to 11:59:59 PM)
    - Extension Reason (dropdown or text input: e.g. "Manager Approval", "Medical Leave")
  - Submits to `api/admin/extend_deadline.php` and instantly refreshes table data.

---

## 🧪 Verification & Test Cases

1. **Test Case 1 (Saroj Kumar Das - HL203):**
   - Attempt 1: Failed (70%)
   - Attempt 2: Passed (100%)
   - **Expected:** Has passed $\rightarrow$ `is_deadline_locked = false`. Can view all modules, attempt history, and certificate.
2. **Test Case 2 (Unattempted User):**
   - Attempts: 0
   - System date simulated on Nov 25.
   - **Expected:** `is_deadline_locked = true`. Full screen lock modal displayed.
3. **Test Case 3 (Single Extension):**
   - Admin extends unattempted user to Nov 29.
   - **Expected:** Only that user unlocks. All other unattempted users remain locked.
4. **Test Case 4 (New Joiner Working Days):**
   - User joins Friday, Nov 27.
   - System calculates 7 working days skipping Sat Nov 28 (if 2nd/3rd), Sun Nov 29, and official holidays.
   - **Expected:** Accurate 7th working day at 11:59:59 PM.

---

## 🚀 Deployment Instructions
1. Run SQL schema creation on live MySQL database:
   - Create `onboard_employee_deadlines`.
   - Seed Batch 1 users.
2. Deploy backend files to `api/`:
   - `api/deadline_helper.php`
   - `api/admin/extend_deadline.php`
   - Update `api/moodle/courses.php` & `launch.php`.
3. Build frontend bundle:
   ```bash
   npm run build
   ```
4. Verify on `https://corhis.hexalearn.com/onboard/`.
