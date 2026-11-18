# HexaLearn Employee Onboarding Portal

A sequential, interactive e-learning and induction platform designed for new hires at **HexaLearn**. Built with **React (TypeScript + Vite)**, **PHP (XAMPP / MySQL)**, and **Articulate Storyline / SCORM** integration, styled under the **HexaOne Design System**.

---

## 📋 Documentation Quick Links
- **[WORKFLOW.md](WORKFLOW.md)**: ⚡ Step-by-step guide on what happens when a new employee lands on the portal.
- **[README_DATABASE.md](README_DATABASE.md)**: 🗄 Exhaustive schema reference for all 7 active MySQL tables.

---

## 📑 Table of Contents
1. [Overview](#-overview)
2. [Core Architecture & Flow](#-core-architecture--flow)
3. [Key Features](#-key-features)
4. [Tech Stack](#-tech-stack)
5. [Directory Structure](#-directory-structure)
6. [Database Setup (MySQL)](#-database-setup-mysql)
7. [Storyline & SCORM Integration](#-storyline--scorm-integration)
8. [Local Development Setup](#-local-development-setup)
9. [Production Deployment](#-production-deployment)

---

## 🌟 Overview

The **HexaLearn Onboarding Portal** guides employees through a structured 10-course induction curriculum. Courses must be completed in order: **Course 1 is unlocked initially, while Courses 2 through 10 remain locked.** When the employee reaches the actual final slide of a course, the next course unlocks automatically. After completing all 10 courses, the employee takes **one comprehensive Final Assessment** to earn their completion certificate.

---

## 🔄 Core Architecture & Flow

```
┌────────────────────────────────────────────────────────────────────────────────────────┐
│                                EMPLOYEE LEARNING JOURNEY                               │
├────────────────────────────────────────────────────────────────────────────────────────┤
│                                                                                        │
│   Course 01 (Unlocked) ──► Reaches Final Slide ──► Course 02 Unlocks                  │
│                                                           │                            │
│   Course 03 Unlocks    ◄── Reaches Final Slide ◄──────────┘                            │
│           │                                                                            │
│           ▼                                                                            │
│   ... (Courses 04 - 09 unlock sequentially) ...                                        │
│           │                                                                            │
│           ▼                                                                            │
│   Course 10 (Final Module) ──► Reaches Final Slide ──► Single Final Assessment Unlocks │
│                                                                  │                     │
│                                                Pass (≥ 75%) ◄────┘                     │
│                                                     │                                  │
│                                                     ▼                                  │
│                                      Induction Certificate Generated                   │
│                                                                                        │
└────────────────────────────────────────────────────────────────────────────────────────┘
```

### Communication Sequence (Storyline ↔ React ↔ PHP)
1. **Employee reaches the final slide** in the Articulate Storyline course embedded in the React player.
2. **Storyline trigger fires** a cross-window message (`window.parent.postMessage`) or SCORM commit (`API.LMSCommit`).
3. **React catches the event**, verifies the course ID, and calls `POST /api/modules/complete.php`.
4. **PHP validates session and prerequisites**, then updates `onboard_module_progress` in MySQL (`is_completed = 1`).
5. **Course 2 unlocks automatically** in React without requiring a full page refresh.

---

## ✨ Key Features

- **Sequential Course Unlocking**: Rigid prerequisite verification on both frontend and backend prevents skipping modules.
- **10 Core Induction Modules**:
  1. *Welcome to HexaLearn & Corporate Vision*
  2. *Company Culture, Values & Code of Conduct*
  3. *Information Security & Data Protection (ISMS)*
  4. *HR Policies, Leaves & Benefits*
  5. *Workplace Health, Safety & Ergonomics*
  6. *HexaLearn Quality Management System (QMS)*
  7. *IT Infrastructure, Tools & Support*
  8. *Client Communication & Professional Etiquette*
  9. *Time Tracking, Timesheet & Project Logging*
  10. *Career Pathways, Performance & Annual Review*
- **Single Final Assessment**:
  - Gated strictly behind completion of all 10 modules.
  - 10 comprehensive scenario-based questions.
  - Passing score: **75%** (8 out of 10).
  - Downloadable/printable official completion certificate upon passing.
- **HexaOne Design Theme**:
  - Primary Brand Color: HexaOne Emerald (`#00A389`)
  - Accent Color: Sky Blue (`#0EA5E9`)
  - Background: Clean Slate (`#F8FAFC`) with crisp white card containers (`#FFFFFF`).
  - Interactive Journey Stepper pipeline tracking all 10 courses + Final Assessment.
- **Dynamic Identity Integration**:
  - Shared PHP session authentication with the **HexaLearn Timesheet** (`hex-timesheet`).
  - Auto-fetches employee name, department, designation, and employee code directly from server session.

---

## 🛠 Tech Stack

| Layer | Technology | Purpose |
| :--- | :--- | :--- |
| **Frontend UI** | React 18, TypeScript, Vite | Single-page application, interactive player modal |
| **Styling** | Tailwind CSS, Lucide React, Radix UI | HexaOne design system, accessible UI components |
| **Backend API** | PHP 7.4+ / 8.x | REST endpoints for module progress and assessments |
| **Database** | MySQL / MariaDB (XAMPP) | Persistent tables with `onboard_*` prefix |
| **E-Learning** | Articulate Storyline 360 / SCORM | Interactive slide modules embedded via iframe |
| **Authentication** | Shared PHP Session (`PHPSESSID`) | Single Sign-On with Hexa-Timesheet |

---

## 📁 Directory Structure

```
onboard/
├── allmodules/                     # Articulate Storyline exported packages
│   └── Course Template/            # Storyline course files (story.html, story_content/)
├── api/                            # PHP Backend Endpoints
│   ├── auth/                       # Authentication and session verification
│   ├── modules/                    # Course listing, slide progress & completion
│   │   ├── list.php                # Fetches courses with prerequisite lock status
│   │   ├── complete.php            # Validates & marks module complete in MySQL
│   │   └── progress.php            # Updates slide progress percentage
│   ├── assessments/                # Final assessment questions, submit & results
│   ├── profile/                    # Dynamic employee profile data
│   └── db.php                      # MySQL database connection config
├── install/                        # Database Migration & Seed Scripts
│   └── seed_10_courses_and_final_assessment.sql # Complete self-contained DDL & data
├── src/                            # React Application Source
│   ├── components/                 # UI components
│   │   ├── CoursePlayerModal.tsx   # Modal player with Storyline iframe & message listener
│   │   ├── CourseJourneyStepper.tsx# Visual pipeline (Courses 1-10 + Assessment)
│   │   ├── Layout.tsx              # Clean HexaLearn header, navigation & logout
│   │   └── ui/                     # Radix UI / shadcn-ui primitives
│   ├── hooks/                      # Custom React hooks
│   │   └── useCourseProgress.ts    # Sequential unlock state manager & API synchronizer
│   ├── pages/                      # Page components (Dashboard, Assessments, etc.)
│   ├── data/                       # Course definitions, slide decks & questions
│   └── index.css                   # HexaOne color tokens & styling utilities
└── vite.config.ts                  # Vite build configuration
```

---

## 🗄 Database Architecture (MySQL)

All portal tables use the unified **`onboard_`** prefix to prevent collisions with the main HexaOne Timesheet system.

For full column breakdowns, data types, and SQL queries, see **[README_DATABASE.md](README_DATABASE.md)**.

### Active Production Tables (7 Tables):
1. **`onboard_moodle_users`**: Single Sign-On (SSO) bridging table mapping `user.uid` to Moodle LMS user IDs.
2. **`onboard_modules`**: Catalog of onboarding induction courses and Moodle course linkages.
3. **`onboard_module_progress`**: Real-time per-employee completion status, progress %, score, and total time spent.
4. **`onboard_module_sessions`**: Fine-grained session telemetry logged on every course launch.
5. **`onboard_assessment_attempts`**: Audit log of assessment scores, attempt timestamps, and pass/fail status.
6. **`onboard_settings`**: Global configuration store for Moodle API base URL and WebService tokens.
7. **`onboard_question_types`**: System reference lookup table for quiz question formats.

*(Note: All legacy prototype tables were cleaned and permanently dropped via `clean_unused_tables.php`).*

---

## 🎯 Storyline & SCORM Integration

### 1. Final Slide Trigger in Articulate Storyline
In Articulate Storyline 360, add an **Execute JavaScript** trigger on the final slide of your course:

```javascript
// Articulate Storyline Final Slide Trigger
if (window.parent && window.parent !== window) {
  window.parent.postMessage({
    source: 'ARTICULATE_STORYLINE',
    type: 'STORYLINE_COURSE_COMPLETED',
    moduleId: 1, // Set corresponding module ID (1 to 10)
    status: 'completed',
    timestamp: new Date().toISOString()
  }, '*');
}
```

### 2. How React Handles the Event
In `src/components/CoursePlayerModal.tsx`, the parent window listens for `message` events:
- Verifies `event.data.source === 'ARTICULATE_STORYLINE'`
- Calls `POST /api/modules/complete.php` with `{ module_id: id }`
- On backend confirmation, advances `useCourseProgress` state, triggering Course 2 to unlock.

---

## 🚀 Local Development Setup

### Prerequisites
- **Node.js** (v18 or higher) & **npm**
- **XAMPP** (Apache + MySQL running)

### Running Frontend
```bash
# Navigate to project directory
cd d:/xaamp/htdocs/onboard

# Install dependencies
npm install

# Start development server
npm run dev
```
The local Vite preview will run at: `http://localhost:8080/`

### Running with XAMPP Apache
Ensure the project is located inside your XAMPP web root:
- Path: `d:\xaamp\htdocs\onboard\`
- Open in browser: `http://localhost/onboard/`

---

## 🌐 Production Deployment

1. Build the production React assets:
   ```bash
   npm run build
   ```
2. The compiled static bundle will be created in `dist/`.
3. Apache handles routing via `.htaccess` so that all client routes redirect to `index.html` while preserving direct access to `/api/` and `/allmodules/`.

---

## 📄 License & Proprietary Information

This project is proprietary software belonging to **HexaLearn Solutions Private Limited**. All rights reserved.
