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

### Batch 2: New Employees (Perpetual Rule: 2026, 2027, 2028, 2029, 2030+)
* **Eligibility:** Any new joiner registered on or after November 25, 2026 (`onboard_moodle_users.created_at >= '2026-11-25 00:00:00'`).
* **Policy:** Exactly **7 Official Working Days** dynamically calculated from their joining/portal entry date.
* **Perpetual 5 to 10+ Year Automated Engine (Zero Hardcoded Years):**
  This rule is 100% dynamic and runs perpetually across all future years (**2026, 2027, 2028, 2029, 2030, 2035+**) without needing code updates.
* **Calendar Exclusions & Working Saturdays Breakdown:**
  1. ❌ **Sundays:** Every Sunday (`date('N') == 7`) is excluded (Company Off-day).
  2. ❌ **2nd & 3rd Saturdays:** Dynamically calculated for ANY month of ANY year:
     - **Mathematical Calendar Law (Valid for all years 2026–2040+):**
       - **1st Saturday:** Always falls on **Days 1 to 7** of the month $\rightarrow$ **Office Working Day** (Counted).
       - **2nd Saturday:** Always falls on **Days 8 to 14** of the month $\rightarrow$ **OFF / Excluded**.
       - **3rd Saturday:** Always falls on **Days 15 to 21** of the month $\rightarrow$ **OFF / Excluded**.
       - **4th Saturday:** Always falls on **Days 22 to 28** of the month $\rightarrow$ **Office Working Day** (Counted).
       - **5th Saturday:** Always falls on **Days 29 to 31** (when present) $\rightarrow$ **Office Working Day** (Counted).
  3. ❌ **Company Holidays:** Queried in real-time from the `holiday_info` table for whatever year the employee joins.
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
 * Uses exact Gregorian calendar math (never assumes dates):
 * - 1st Saturday: Days 1 to 7   -> Working Day
 * - 2nd Saturday: Days 8 to 14  -> OFF (Company Holiday)
 * - 3rd Saturday: Days 15 to 21 -> OFF (Company Holiday)
 * - 4th Saturday: Days 22 to 28 -> Working Day
 * - 5th Saturday: Days 29 to 31 -> Working Day
 */
function isSecondOrThirdSaturday(DateTime $date): bool {
    // 6 = Saturday in ISO-8601 (1=Mon ... 7=Sun)
    if ((int)$date->format('N') !== 6) {
        return false;
    }
    $dayOfMonth = (int)$date->format('j');
    // Day 8-14 is guaranteed 2nd Saturday, Day 15-21 is guaranteed 3rd Saturday
    return ($dayOfMonth >= 8 && $dayOfMonth <= 21);
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
4. **Test Case 4 (Actual Calendar: Nov 25, 2026 Batch 2 New Joiner):**
   - User joins **Wednesday, Nov 25, 2026**.
   - Calendar day breakdown:
     - Day 1: Wed, Nov 25 -> Working Day 1
     - Day 2: Thu, Nov 26 -> Working Day 2
     - Day 3: Fri, Nov 27 -> Working Day 3
     - Day 4: Sat, Nov 28 -> **4th Saturday (Day 28: Working Day 4)**
     - Sun, Nov 29 -> Sunday (OFF / Excluded)
     - Day 5: Mon, Nov 30 -> Working Day 5
     - Day 6: Tue, Dec 01 -> Working Day 6
     - Day 7: Wed, Dec 02 -> Working Day 7
   - **Expected:** Deadline is **Wednesday, Dec 02, 2026 at 23:59:59 IST**.

5. **Test Case 5 (Actual Calendar: Friday, Nov 27, 2026 Joiner):**
   - User joins **Friday, Nov 27, 2026**.
   - Calendar day breakdown:
     - Day 1: Fri, Nov 27 -> Working Day 1
     - Day 2: Sat, Nov 28 -> **4th Saturday (Day 28: Working Day 2)**
     - Sun, Nov 29 -> Sunday (OFF / Excluded)
     - Day 3: Mon, Nov 30 -> Working Day 3
     - Day 4: Tue, Dec 01 -> Working Day 4
     - Day 5: Wed, Dec 02 -> Working Day 5
     - Day 6: Thu, Dec 03 -> Working Day 6
     - Day 7: Fri, Dec 04 -> Working Day 7
   - **Expected:** Deadline is **Friday, Dec 04, 2026 at 23:59:59 IST**.

6. **Test Case 6 (Actual Calendar: 2nd & 3rd Saturday Exclusion):**
   - User joins **Thursday, Dec 10, 2026**.
   - Calendar day breakdown:
     - Day 1: Thu, Dec 10 -> Working Day 1
     - Day 2: Fri, Dec 11 -> Working Day 2
     - Sat, Dec 12 -> **2nd Saturday (Day 12: OFF / Excluded)**
     - Sun, Dec 13 -> Sunday (OFF / Excluded)
     - Day 3: Mon, Dec 14 -> Working Day 3
     - Day 4: Tue, Dec 15 -> Working Day 4
     - Day 5: Wed, Dec 16 -> Working Day 5
     - Day 6: Thu, Dec 17 -> Working Day 6
     - Day 7: Fri, Dec 18 -> Working Day 7
     - Sat, Dec 19 -> **3rd Saturday (Day 19: OFF / Excluded)**
   - **Expected:** Deadline is **Friday, Dec 18, 2026 at 23:59:59 IST**.

7. **Test Case 7 (Future Year Proof: 2027 Joiner):**
   - User joins in **January 2027** (e.g., Monday, Jan 04, 2027).
   - Calendar day breakdown:
     - Day 1: Mon, Jan 04, 2027 -> Working Day 1
     - Day 2: Tue, Jan 05, 2027 -> Working Day 2
     - Day 3: Wed, Jan 06, 2027 -> Working Day 3
     - Day 4: Thu, Jan 07, 2027 -> Working Day 4
     - Day 5: Fri, Jan 08, 2027 -> Working Day 5
     - Sat, Jan 09, 2027 -> **2nd Saturday (Day 9: OFF / Excluded)**
     - Sun, Jan 10, 2027 -> Sunday (OFF / Excluded)
     - Day 6: Mon, Jan 11, 2027 -> Working Day 6
     - Day 7: Tue, Jan 12, 2027 -> Working Day 7
   - **Expected:** Deadline is **Tuesday, Jan 12, 2027 at 23:59:59 IST**.
   - *Proves zero code changes needed when 2027, 2028, or 2029 arrives.*

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
