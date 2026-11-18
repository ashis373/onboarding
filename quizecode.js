
(function () {
    var player = GetPlayer();
    if (!player) return;

    // Read Storyline variables
    var quizScore = Number(player.GetVar("Quiz_score")) || 0;
    var attempt = Number(player.GetVar("attempt")) || 1;

    // Find Moodle SCORM 1.2 API
    var lms = window.API || (window.parent && window.parent.API) || null;

    var studentId = null;

    if (lms) {
        try {
            studentId = lms.LMSGetValue("cmi.core.student_id");
        } catch (e) {
            console.warn("Unable to retrieve Moodle student ID");
        }
    }

    // Send assessment data to PHP REST API
    fetch("https://hexalearn.com/internal/hex-timesheet/onboard/api/moodle/track_assessment.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/json"
        },
        credentials: "include",
        body: JSON.stringify({
            quiz_score: quizScore,
            attempt: attempt,
            student_id: studentId
        })
    })
        .then(function (res) {
            if (!res.ok) throw new Error("API request failed");
            return res.json();
        })
        .then(function (data) {
            console.log("[Assessment Track]", data);
        })
        .catch(function (err) {
            console.error("[Assessment Track Error]", err);
        });
})();
