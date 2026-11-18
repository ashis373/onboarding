# Articulate Storyline Developer Guide: M11 Induction Assessment

This guide provides step-by-step instructions for Storyline developers to configure variables, triggers, and JavaScript integration for the **HexaLearn CorHis Induction Assessment (M11 - Course ID 3)**.

---

## 1. Project Variables Setup

Open **Manage Project Variables** (`Ctrl + Shift + V`) in Articulate Storyline and define the following **4 variables**:

| Variable Name | Type | Default Value | Description |
| :--- | :--- | :--- | :--- |
| **`attempt`** | **Number** | `1` | Tracks the active attempt number (`1` for first try, `2` for retake). |
| **`Quiz_score`** | **Number** | `0` | Stores the candidate's final quiz percentage (0–100%). |
| **`Final_Submit`** | **True/False** | `False` | Safety guard: prevents premature API calls on intermediate question slides. |
| **`Submission_Sent`** | **True/False** | `False` | Deduplication guard: ensures only 1 API request is sent per attempt. |

---

## 2. Trigger Configuration (Step-by-Step)

### A. On the Result Slide (When Timeline Starts)
Place these triggers on the **Result Slide** itself (not on question slides):

1. **Trigger 1 (Set Guard Flag):**
   - **Action**: Adjust variable
   - **Variable**: `Final_Submit`
   - **Operator**: `= Set`
   - **Value**: `True`
   - **When**: Timeline starts on this slide

2. **Trigger 2 (Execute Assessment Tracker):**
   - **Action**: Execute JavaScript
   - **Script**: *(Paste the JavaScript code from Section 3 below)*
   - **When**: Timeline starts on this slide

> [!IMPORTANT]
> **Trigger Order Matters**: Make sure **Trigger 1 (`Final_Submit = True`)** is listed **ABOVE** **Trigger 2 (`Execute JavaScript`)** in Storyline's Trigger panel.

---

### B. On the "Retry Quiz" Button (Attempt 1 Failure Layer)
When a candidate scores below 80% on Attempt 1, Storyline displays the **Retry Quiz** button. 
Add these triggers to the **Retry Quiz** button in this **exact order**:

1. **Trigger 1**: Set `attempt` = `2` when user clicks this button.
2. **Trigger 2**: Set `Final_Submit` = `False` when user clicks this button.
3. **Trigger 3**: Set `Submission_Sent` = `False` when user clicks this button.
4. **Trigger 4**: Reset Results (Select your Quiz / Result Slide) when user clicks this button.
5. **Trigger 5**: Jump to Slide (Question 1) when user clicks this button.

---

### C. Slide Master & Question Slides Rule
- **CRITICAL**: Do **NOT** put any "Execute JavaScript" triggers on Question Slides (Slides 1 to 10) or on the Slide Master.
- The JavaScript must execute **exclusively** on the **Result Slide**.

---

## 3. JavaScript Code to Paste into Trigger 2

Paste this exact code into the **Execute JavaScript** trigger on the Result Slide:

```javascript
(function () {
    var player = GetPlayer();
    if (!player) return;

    // 1. Guard against firing on intermediate question slides
    var finalSubmit = player.GetVar("Final_Submit");
    if (typeof finalSubmit !== "undefined" && finalSubmit !== null && finalSubmit !== true) {
        console.log("[Assessment Track] Skipped: Not on final result slide.");
        return;
    }

    // 2. Prevent duplicate submissions for the same attempt
    var submissionSent = player.GetVar("Submission_Sent");
    if (submissionSent === true) {
        console.log("[Assessment Track] Already submitted for this attempt.");
        return;
    }
    if (typeof submissionSent !== "undefined" && submissionSent !== null) {
        player.SetVar("Submission_Sent", true);
    }

    // 3. Read Storyline variables
    var quizScore = Number(player.GetVar("Quiz_score")) || 0;
    var attempt = Number(player.GetVar("attempt")) || 1;

    // 4. Retrieve Moodle SCORM 1.2 student_id if running inside LMS iframe
    var lms = window.API || (window.parent && window.parent.API) || null;
    var studentId = null;
    if (lms) {
        try {
            studentId = lms.LMSGetValue("cmi.core.student_id");
        } catch (e) {
            console.warn("Unable to retrieve Moodle student ID", e);
        }
    }

    console.log("Assessment Data:", {
        attempt: attempt,
        quiz_score: quizScore,
        student_id: studentId
    });

    // 5. Target the CorHis Onboarding API endpoint
    var apiUrl = "https://corhis.hexalearn.com/onboard/api/moodle/track_assessment.php";

    fetch(apiUrl, {
        method: "POST",
        headers: {
            "Content-Type": "application/json"
        },
        credentials: "include",
        body: JSON.stringify({
            quiz_score: quizScore,
            attempt: attempt,
            student_id: studentId,
            course_id: 3,
            final_submit: true
        })
    })
    .then(function (res) {
        return res.json();
    })
    .then(function (data) {
        console.log("[Assessment Result Saved]", data);
    })
    .catch(function (err) {
        console.error("[Assessment Save Error]", err);
        // On network failure, re-enable submission
        if (typeof submissionSent !== "undefined" && submissionSent !== null) {
            player.SetVar("Submission_Sent", false);
        }
    });
})();
```

---

## 4. How the Two-Attempt Workflow Works

```
┌────────────────────────────────────────────────────────────────────────┐
│                        Candidate Starts Quiz                           │
│                 `attempt` = 1, `Final_Submit` = False                  │
└──────────────────────────────────┬─────────────────────────────────────┘
                                   │
                                   ▼
          Candidate answers 10 questions (No API calls fired)
                                   │
                                   ▼
┌────────────────────────────────────────────────────────────────────────┐
│                        Result Slide: Attempt 1                         │
│                  Sends: { attempt: 1, quiz_score: X }                  │
└──────────────────┬──────────────────────────────────┬──────────────────┘
                   │                                  │
          Score ≥ 80% (Pass)                 Score < 80% (Fail)
                   │                                  │
                   ▼                                  ▼
         ✅ Passed Screen                      ❌ Failed Screen Layer 1
         Course Completed                      "One final attempt remaining"
         NO email sent                         Shows [Retry Quiz] button
                                               Course remains UNLOCKED
                                               NO email sent
                                                      │
                                                      ▼ (User clicks "Retry Quiz")
                                               `attempt` set to 2
                                               `Final_Submit` reset to False
                                               Quiz reset & jumps to Q1
                                                      │
                                                      ▼
                              Candidate answers 10 questions (Attempt 2)
                                                      │
                                                      ▼
┌────────────────────────────────────────────────────────────────────────┐
│                        Result Slide: Attempt 2                         │
│                  Sends: { attempt: 2, quiz_score: Y }                  │
└──────────────────┬──────────────────────────────────┬──────────────────┘
                   │                                  │
          Score ≥ 80% (Pass)                 Score < 80% (Fail)
                   │                                  │
                   ▼                                  ▼
         ✅ Passed Screen                      🔒 Lockout Screen Layer 2
         Course Completed                      "Passing score not achieved.
         NO email sent                          Please reach out to your Supervisor."
                                               [Retry Quiz] button is HIDDEN
                                               Course is LOCKED in Database
                                               Automated Alert Email Dispatched
                                               to Department Head + CC
```

---

## 5. Result Slide Visual States Summary

| Scenario | Candidate Screen | Retry Button | System Action |
| :--- | :--- | :--- | :--- |
| **Attempt 1: Score $\ge$ 80%** | Green Success screen | No | Marked **Completed** (100%). Unlocked. No email sent. |
| **Attempt 1: Score &lt; 80%** | Red "One final attempt remaining" | **Yes** (`[Retry Quiz]`) | Recorded as Attempt 1 Fail. Course remains **unlocked**. No email sent. |
| **Attempt 2: Score $\ge$ 80%** | Green Success screen | No | Marked **Completed** (100%). Unlocked. No email sent. |
| **Attempt 2: Score &lt; 80%** | Red "Please reach out to your Supervisor" | **Hidden / Disabled** | Recorded as Attempt 2 Fail. Course is **LOCKED**. Alert email sent to Dept Head. |

---

## 6. Testing & Console Verification

When testing in Chrome DevTools (`F12` $\rightarrow$ **Console**):

1. **On Slide 1–10**: No `[Assessment Track]` logs should appear.
2. **On Result Slide (Attempt 1)**:
   ```
   Assessment Data: { attempt: 1, quiz_score: 30, student_id: "..." }
   [Assessment Result Saved] { success: true, passed: false, can_retry: true, locked: false }
   ```
3. **On Clicking "Retry Quiz"**: Questions reset, no errors.
4. **On Result Slide (Attempt 2)**:
   ```
   Assessment Data: { attempt: 2, quiz_score: 20, student_id: "..." }
   [Assessment Result Saved] { success: true, passed: false, can_retry: false, locked: true }
   ```
