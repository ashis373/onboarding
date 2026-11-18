// ================================================================
// HexaLearn Master Slide Heartbeat (Single Script for All Slides)
// Place on the Storyline Slide Master:
// Trigger: When timeline starts on Master Slide -> Execute JavaScript
// ================================================================
(function () {
    var player = GetPlayer();
    if (!player) return;

    // ============================================================
    // CONFIGURE FOR THIS MODULE (Only change these 2 numbers):
    // ============================================================
    var MODULE_ID = 9;     // HexaLearn Module ID (e.g. 5, 7, 8, 9)
    var TOTAL_SLIDES = 19; // Total slides in this Storyline package
    // ============================================================

    // 1. Read dynamic variables from Storyline (or fallback to configuration above)
    var currentSlide = Number(player.GetVar("CurrentSlide"));
    var totalSlides = Number(player.GetVar("TotalSlides")) || TOTAL_SLIDES;
    var moduleId = Number(player.GetVar("ModuleId")) || MODULE_ID;

    // Validate slide number
    if (!currentSlide || isNaN(currentSlide) || currentSlide < 1) return;

    // 2. Prevent duplicate calls if already reported this slide
    if (window.__lastTrackedSlide === currentSlide) return;
    window.__lastTrackedSlide = currentSlide;

    // 3. Update Moodle SCORM bookmark & student_id
    var lms = (window.parent && window.parent.API) ? window.parent.API : (window.API || null);
    var studentId = null;
    if (lms) {
        try {
            studentId = lms.LMSGetValue("cmi.core.student_id");
            lms.LMSSetValue("cmi.core.lesson_location", "Page " + currentSlide + "/" + totalSlides);
            lms.LMSCommit("");
        } catch (e) { }
    }

    // 4. Report current slide to HexaLearn MySQL API
    fetch("https://hexalearn.com/internal/hex-timesheet/onboard/api/moodle/track_slide.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "include",
        body: JSON.stringify({
            module_id: moduleId,
            current_slide: currentSlide,
            total_slides: totalSlides,
            student_id: studentId
        })
    })
    .then(function (res) { return res.json(); })
    .then(function (data) {
        console.log("[Master Slide Track] Slide " + data.current_slide + "/" + data.total_slides +
                    " | Progress: " + data.progress_percent + "% | Done: " + data.is_completed);
    })
    .catch(function (err) {
        console.error("[Master Slide Track Error]", err);
    });
})();
