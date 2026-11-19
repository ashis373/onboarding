/**
 * HexaLearn CorHis Onboarding - Induction Assessment Storyline Tracker (Bulletproof v2)
 * File: E:\onboardlatestdrive\storlyneguide\quzefinal.js
 * 
 * SETUP IN ARTICULATE STORYLINE:
 * 1. Result Slide (When Timeline Starts on this slide):
 *    - Trigger 1: Execute JavaScript (Paste this script)
 * 
 * 2. "Retry Quiz" Button on Result Slide (When User Clicks):
 *    - Trigger 1: Set attempt = 2
 *    - Trigger 2: Set Submission_Sent = False
 *    - Trigger 3: Reset Results (Quiz)
 *    - Trigger 4: Jump to Slide (Question 1)
 */

(function () {
    var player = GetPlayer();
    if (!player) return;

    // 1. Prevent duplicate submissions for the same attempt
    var submissionSent = player.GetVar("Submission_Sent");
    if (submissionSent === true) {
        console.log("[Assessment Track] Already submitted for this attempt.");
        return;
    }

    // Mark as submitted immediately to prevent multiple triggers
    if (typeof submissionSent !== "undefined" && submissionSent !== null) {
        player.SetVar("Submission_Sent", true);
    }
    try {
        player.SetVar("Final_Submit", true);
    } catch (e) { }

    // 2. Read Storyline variables
    var quizScore = Number(player.GetVar("Quiz_score")) || 0;
    var attempt = Number(player.GetVar("attempt")) || 1;

    // 3. Retrieve Moodle SCORM 1.2 student_id if running inside LMS iframe
    var lms = window.API || (window.parent && window.parent.API) || null;
    var studentId = null;
    if (lms) {
        try {
            studentId = lms.LMSGetValue("cmi.core.student_id");
        } catch (e) {
            console.warn("Unable to retrieve Moodle student ID", e);
        }
    }

    console.log("[Assessment Track] Submitting Attempt " + attempt + " with Score: " + quizScore + "%", {
        attempt: attempt,
        quiz_score: quizScore,
        student_id: studentId
    });

    // 4. Target the CorHis Onboarding API endpoint
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
            console.log("[Assessment Result Saved Successfully]", data);
        })
        .catch(function (err) {
            console.error("[Assessment Save Error]", err);
            // On network failure, allow retry
            if (typeof submissionSent !== "undefined" && submissionSent !== null) {
                player.SetVar("Submission_Sent", false);
            }
        });
})();
