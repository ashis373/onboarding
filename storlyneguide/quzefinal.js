/**
 * HexaLearn CorHis Onboarding - Induction Assessment Storyline Tracker (Final)
 * File: E:\onboardlatestdrive\quzefinal.js
 * 
 * SETUP IN ARTICULATE STORYLINE:
 * 1. Result Slide (When Timeline Starts):
 *    - Trigger 1: Set Final_Submit = True
 *    - Trigger 2: Execute JavaScript (Paste this script)
 * 
 * 2. "Retry Quiz" Button on Result Slide (When User Clicks):
 *    - Trigger 1: Set attempt = 2
 *    - Trigger 2: Set Final_Submit = False
 *    - Trigger 3: Set Submission_Sent = False (if variable exists)
 *    - Trigger 4: Reset Results (Quiz)
 *    - Trigger 5: Jump to Slide (Question 1)
 */

(function () {
    var player = GetPlayer();
    if (!player) return;

    // 1. Guard against firing on intermediate question slides (e.g. Slide 5)
    // Only allows execution when candidate reaches the final Result Slide
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
