# Storyline Quick Setup: Which Trigger to Add Where

## 1. Manage Variables (`Ctrl + Shift + V`)
Add these 3 variables:
* **`attempt`** &rarr; Number (Default: `1`)
* **`Quiz_score`** &rarr; Number (Default: `0`)
* **`Submission_Sent`** &rarr; True/False (Default: `False`)

---

## 2. On Result Slide (When Timeline Starts)
Add **only 1 trigger** on the Result Slide:
* **Action:** `Execute JavaScript` (Paste code from `quzefinal.js`)
* **When:** `Timeline starts on this slide`

---

## 3. On "Retry Quiz" Button (When User Clicks)
Add these **4 triggers** on the "Retry Quiz" button:
1. `Set attempt = 2`
2. `Set Submission_Sent = False`
3. `Reset Results (Quiz)`
4. `Jump to Slide (Question 1)`
