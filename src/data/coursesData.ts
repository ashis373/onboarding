export interface CourseSlide {
  id: number;
  title: string;
  subtitle?: string;
  badge?: string;
  content: string;
  bullets?: string[];
  callout?: {
    type: 'tip' | 'important' | 'quote' | 'checklist';
    text: string;
  };
  keyTakeaway?: string;
  interactiveCheck?: {
    question: string;
    options: string[];
    correctIndex: number;
    explanation: string;
  };
}

export interface CourseDefinition {
  id: number;
  title: string;
  subtitle: string;
  type: "video" | "pdf" | "document" | "interactive";
  category: string;
  duration: string;
  duration_minutes: number;
  video_url?: string | null;
  file_url?: string | null;
  scorm_url?: string | null;
  roadmap_day: number;
  gradient: string;
  accentGlow: string;
  tagline: string;
  coverLabel: string;
  theme: "video" | "handbook" | "security" | "team" | "safety" | "privacy" | "dei" | "tools" | "ethics" | "graduation";
  slides: CourseSlide[];
}

export const COURSES_DATA: CourseDefinition[] = [
  {
    id: 1,
    title: "Welcome & Company Overview",
    subtitle: "Orientation, leadership vision, company heritage & core values",
    type: "video",
    category: "ORIENTATION",
    duration: "15 min",
    duration_minutes: 15,
    video_url: "https://www.youtube.com/embed/dQw4w9WgXcQ",
    scorm_url: "/allmodules/Course Template/story.html",
    roadmap_day: 1,
    gradient: "from-blue-600 via-indigo-700 to-slate-950",
    accentGlow: "rgba(59, 130, 246, 0.4)",
    tagline: "SMART START & LEADERSHIP",
    coverLabel: "COMPANY OVERVIEW",
    theme: "video",
    slides: [
      {
        id: 1,
        title: "Welcome to HexaLearn Solutions",
        subtitle: "A vibrant journey begins today",
        badge: "ORIENTATION • SLIDE 1 OF 5",
        content: "Welcome to HexaLearn! We are excited to welcome you into our global team of creators, engineers, instructional designers, and problem solvers. Since our inception, HexaLearn has been driven by a single commitment: designing transformative digital learning experiences that empower millions of learners worldwide.",
        bullets: [
          "Founded with passion for high-impact educational technology and eLearning solutions.",
          "Trusted partner to Fortune 500 enterprises, academic institutions, and training academies.",
          "A multidisciplinary team where your unique perspective and ideas truly matter."
        ],
        callout: {
          type: "quote",
          text: "\"When it comes to content, we know it, we do it & we make our team feel proud of it.\" — HexaLearn Creed"
        },
        keyTakeaway: "You are now part of an organization that values craftsmanship, empathy, and innovation."
      },
      {
        id: 2,
        title: "Our Purpose & Core Values",
        subtitle: "The compass that guides every project",
        badge: "CORE VALUES • SLIDE 2 OF 5",
        content: "Our core values aren't just posters on a wall; they reflect how we collaborate with each other, how we engineer our code, and how we deliver promises to our clients every single day.",
        bullets: [
          "Excellence & Precision: Crafting content and code that sets the benchmark in quality.",
          "Continuous Learning: Staying curious, mastering new tools, and leveling up as a team.",
          "Transparent Collaboration: Open communication, active listening, and collective accountability.",
          "Empathy & Mutual Respect: Supporting teammates, celebrating wins, and fostering psychological safety."
        ],
        callout: {
          type: "tip",
          text: "Tip: Connect with your peer onboarding buddy this week to learn how our values come to life in your department."
        },
        keyTakeaway: "Every interaction at HexaLearn is anchored in respect, continuous growth, and excellence."
      },
      {
        id: 3,
        title: "What We Do & How We Deliver",
        subtitle: "eLearning architectures and digital mastery",
        badge: "CAPABILITIES • SLIDE 3 OF 5",
        content: "HexaLearn operates across multiple modern disciplines in the educational and corporate training landscape. From bespoke Storyline SCORM modules to cutting-edge AI learning agents, we pioneer the future of work.",
        bullets: [
          "Custom eLearning & Interactive Simulation: Articulate Storyline, Rise, HTML5 games.",
          "Enterprise LMS Integration: SCORM 1.2, SCORM 2004, xAPI (Tin Can), LTI, and cmi5 standards.",
          "EdTech Web Platforms: Modern React, Next.js, PHP, cloud microservices, and secure portals.",
          "Mobile Learning & Micro-learning: Accessible anywhere, on any device, with seamless state sync."
        ],
        callout: {
          type: "important",
          text: "Notice: You will explore our Articulate Storyline interactive modules during this onboarding program!"
        },
        keyTakeaway: "We blend deep instructional design science with modern web technology."
      },
      {
        id: 4,
        title: "Your First Week Success Roadmap",
        subtitle: "Setting up your foundation for success",
        badge: "MILESTONES • SLIDE 4 OF 5",
        content: "Your onboarding is organized into clear, stress-free milestones. In your first week, your primary goal is learning our ecosystem and connecting with your team.",
        bullets: [
          "Day 1: Complete your 10 Induction courses and explore the employee portal.",
          "Day 2: IT credential verification, Git repository access, and development environment setup.",
          "Day 3: Department introduction meeting and first 1-on-1 with your reporting manager.",
          "Day 4-5: Shadowing a peer on an active sprint and taking your Final Assessment."
        ],
        interactiveCheck: {
          question: "What is your main objective for Day 1 of onboarding?",
          options: [
            "Submit 40 hours of code commits immediately",
            "Complete induction modules, meet your buddy, and understand company culture",
            "Rebuild the company website from scratch",
            "Skip all guidelines and join client calls"
          ],
          correctIndex: 1,
          explanation: "Exactly! Day 1 is dedicated to orientation, company culture, and completing your induction courses."
        },
        keyTakeaway: "Take your time to absorb the material and ask questions freely."
      },
      {
        id: 5,
        title: "Course 1 Wrap-up & Final Slide",
        subtitle: "Congratulations on finishing your first module!",
        badge: "FINAL SLIDE • READY TO UNLOCK COURSE 2",
        content: "You have reached the final slide of Course 1 (Welcome & Company Overview)! By completing this course, you have unlocked Course 2: Company Handbook.",
        bullets: [
          "Completed: Company heritage, mission, and leadership vision.",
          "Understood: Core values and our dedication to high quality digital learning.",
          "Next Up in Course 2: Workplace policies, leave guidelines, working hours, and benefits."
        ],
        callout: {
          type: "checklist",
          text: "Action: Click the 'Complete & Unlock Course 2' button below to record your completion and unlock Course 2."
        },
        keyTakeaway: "Course 1 is complete! Course 2 is now ready for you."
      }
    ]
  },
  {
    id: 2,
    title: "Company Handbook",
    subtitle: "Workplace policies, employee rights, leaves & benefits guide",
    type: "pdf",
    category: "POLICY & HANDBOOK",
    duration: "20 min",
    duration_minutes: 20,
    file_url: "https://corhis.hexalearn.com/onboard/docs/handbook.pdf",
    roadmap_day: 1,
    gradient: "from-emerald-700 via-teal-850 to-slate-950",
    accentGlow: "rgba(16, 185, 129, 0.4)",
    tagline: "CORE POLICIES & BENEFITS",
    coverLabel: "EMPLOYEE HANDBOOK",
    theme: "handbook",
    slides: [
      {
        id: 1,
        title: "Introduction to the Employee Handbook",
        subtitle: "Your essential guide to life at HexaLearn",
        badge: "HANDBOOK • SLIDE 1 OF 5",
        content: "The HexaLearn Employee Handbook sets out the mutual expectations between you and the company. It serves as a single source of truth for company policies, professional standards, and employment benefits.",
        bullets: [
          "Applicable to all full-time, part-time, and contractual teammates across all locations.",
          "Designed to protect employee rights and ensure a safe, fair, and transparent environment.",
          "Regularly updated to reflect local regulations, wellness initiatives, and modern best practices."
        ],
        callout: {
          type: "tip",
          text: "You can download the full PDF copy anytime from your Dashboard or preview it directly in the portal."
        },
        keyTakeaway: "Our policies exist to support your well-being, growth, and psychological safety."
      },
      {
        id: 2,
        title: "Working Hours, Flexibility & Attendance",
        subtitle: "Managing your time with accountability",
        badge: "ATTENDANCE • SLIDE 2 OF 5",
        content: "We believe in outcome-based work supported by predictable availability. Our core working hours ensure smooth cross-functional coordination while accommodating flexibility.",
        bullets: [
          "Standard Hours: Monday through Friday, 9:30 AM to 6:30 PM (with core collaboration window 11:00 AM – 5:00 PM).",
          "Hex-Timesheet Logging: Daily timesheet entries must be logged by the end of each working day.",
          "Breaks & Rest: 1 hour allocated for lunch with recommended hourly stretch breaks.",
          "Punctuality: If running late or facing unexpected emergencies, inform your team lead on Slack by 10:00 AM."
        ],
        callout: {
          type: "important",
          text: "Timesheets are accessed via the single sign-on link in your top navigation menu."
        },
        keyTakeaway: "Consistent communication and timely timesheet logging keep the team aligned."
      },
      {
        id: 3,
        title: "Leave Management & Time Off",
        subtitle: "Taking time to recharge and rest",
        badge: "LEAVES • SLIDE 3 OF 5",
        content: "We encourage every team member to take their earned leaves to recharge. Healthy work-life balance is a cornerstone of our high-performing culture.",
        bullets: [
          "Earned / Casual Leave (CL): Accrued monthly, ideal for personal commitments and planned vacations.",
          "Sick Leave (SL): Allocated for medical illness; a medical certificate is required for leaves exceeding 2 consecutive days.",
          "Public Holidays: Published at the beginning of each calendar year on the portal.",
          "Applying for Leave: Submit all planned leave requests at least 5 business days in advance via the HR portal."
        ],
        callout: {
          type: "checklist",
          text: "Always set an Out-Of-Office (OOO) status on Slack and update your team before going on leave."
        },
        keyTakeaway: "Plan your leaves proactively to ensure smooth coverage for ongoing deliverables."
      },
      {
        id: 4,
        title: "Employee Benefits & Wellness",
        subtitle: "Supporting you beyond your daily work",
        badge: "BENEFITS • SLIDE 4 OF 5",
        content: "HexaLearn provides competitive compensation packages and comprehensive healthcare benefits designed to keep you and your family protected.",
        bullets: [
          "Group Health Insurance: Comprehensive medical coverage including hospitalization and cashless network hospitals.",
          "Learning Allowance: Annual subsidy for professional certifications, tech books, and course subscriptions.",
          "Employee Assistance Program (EAP): Confidential counseling and mental health advisory support available 24/7.",
          "Performance Bonuses & Rewards: Periodic appraisal cycles recognizing outstanding individual and team impact."
        ],
        interactiveCheck: {
          question: "How far in advance should planned vacation leaves be submitted?",
          options: [
            "30 minutes before the vacation starts",
            "At least 5 business days in advance",
            "After returning from the vacation",
            "Leave requests are never required"
          ],
          correctIndex: 1,
          explanation: "Planned leaves should be submitted at least 5 business days in advance to allow team workload balancing."
        },
        keyTakeaway: "Take advantage of our health insurance, learning budget, and wellness resources."
      },
      {
        id: 5,
        title: "Handbook Wrap-up & Final Slide",
        subtitle: "Ready to proceed to IT Security",
        badge: "FINAL SLIDE • READY TO UNLOCK COURSE 3",
        content: "You have reviewed the key tenets of the HexaLearn Company Handbook! By reaching this final slide, you have fulfilled the requirements for Course 2.",
        bullets: [
          "Completed: Working hours, timesheet policies, and core team attendance.",
          "Reviewed: Leave entitlements, planned vacation procedures, and health benefits.",
          "Next Up in Course 3: IT Security Guidelines, password policies, phishing defense, and VPN safety."
        ],
        callout: {
          type: "checklist",
          text: "Click 'Complete & Unlock Course 3' to mark Course 2 complete and unlock Course 3."
        },
        keyTakeaway: "Course 2 complete! Course 3 (IT Security Guidelines) is now unlocked."
      }
    ]
  },
  {
    id: 3,
    title: "IT Security Guidelines",
    subtitle: "Password protocols, phishing defense & digital asset safety",
    type: "document",
    category: "CYBERSECURITY",
    duration: "20 min",
    duration_minutes: 20,
    roadmap_day: 1,
    gradient: "from-cyan-700 via-blue-900 to-slate-950",
    accentGlow: "rgba(6, 182, 212, 0.4)",
    tagline: "DIGITAL ASSET PROTECTION",
    coverLabel: "CYBER SECURITY",
    theme: "security",
    slides: [
      {
        id: 1,
        title: "Cybersecurity at HexaLearn",
        subtitle: "Protecting our clients, teammates, and source code",
        badge: "SECURITY • SLIDE 1 OF 5",
        content: "As a technology and eLearning provider, HexaLearn is entrusted with confidential client data, proprietary codebases, and sensitive learner records. Maintaining high cybersecurity vigilance is everyone's responsibility.",
        bullets: [
          "Cyber threats target people first, technology second.",
          "A single compromised credential can impact entire corporate repositories and clients.",
          "Compliance with ISO 27001 and international security frameworks is strictly maintained."
        ],
        callout: {
          type: "important",
          text: "Never write down passwords, share credentials on Slack, or email company access keys."
        },
        keyTakeaway: "Security is not just an IT task; it is a shared mindset practiced every minute."
      },
      {
        id: 2,
        title: "Password Standards & Multi-Factor Auth (MFA)",
        subtitle: "Building strong digital defenses",
        badge: "CREDENTIALS • SLIDE 2 OF 5",
        content: "Strong authentication prevents unauthorized intrusions into corporate accounts and systems.",
        bullets: [
          "Password Complexity: Minimum 12 characters combining uppercase, lowercase, numbers, and symbols.",
          "Unique Passwords: Never reuse personal passwords for corporate HexaLearn accounts.",
          "Multi-Factor Authentication (MFA): Mandatory on all email, GitHub, AWS, and portal logins.",
          "Password Managers: Use company-approved password managers; never save passwords in plain text notes."
        ],
        callout: {
          type: "tip",
          text: "Tip: Use passphrases such as 'Purple$Falcon#Baking99!' for high entropy that is easy to remember."
        },
        keyTakeaway: "MFA and complex, unique passwords form your first line of defense."
      },
      {
        id: 3,
        title: "Phishing Awareness & Email Safety",
        subtitle: "Detecting deception before you click",
        badge: "PHISHING DEFENSE • SLIDE 3 OF 5",
        content: "Phishing remains the #1 entry point for cyberattacks worldwide. Attackers frequently impersonate executive leaders, IT support, or payment vendors.",
        bullets: [
          "Inspect the Sender: Check the exact domain in the 'From' address (e.g., watch out for hexalean.com or hexalearn-support.net).",
          "Urgency Triggers: Be suspicious of emails demanding immediate action, gift cards, or wire transfers.",
          "Hover Before Clicking: Always hover over links to inspect the actual destination URL.",
          "Never Open Unexpected Attachments: Especially .zip, .exe, .scr, or macro-enabled documents."
        ],
        callout: {
          type: "checklist",
          text: "If you receive a suspicious email, click the 'Report Phishing' button or forward it to security@hexalearn.com."
        },
        keyTakeaway: "When in doubt, stop and verify through an independent channel before clicking."
      },
      {
        id: 4,
        title: "Workstation Locking, Devices & VPN",
        subtitle: "Safe computing wherever you work",
        badge: "DEVICE HYGIENE • SLIDE 4 OF 5",
        content: "Whether working in the HexaLearn office or remotely, your physical device and network connection must remain strictly defended.",
        bullets: [
          "Lock Screen Rule: Always press Win + L (Windows) or Cmd + Ctrl + Q (Mac) whenever stepping away from your desk.",
          "Company VPN: Mandatory whenever accessing internal staging servers, databases, or public Wi-Fi networks.",
          "Clean Desk & USB Policy: Unauthorized external USB drives must never be plugged into company machines.",
          "Software Updates: Install operating system patches and antivirus updates as soon as prompted."
        ],
        interactiveCheck: {
          question: "What should you do immediately whenever stepping away from your computer?",
          options: [
            "Leave all internal client files open on the screen",
            "Lock your screen (Win + L / Cmd + Ctrl + Q)",
            "Ask a stranger to watch your machine",
            "Disable your antivirus software"
          ],
          correctIndex: 1,
          explanation: "Always lock your screen whenever stepping away, even for just a few moments!"
        },
        keyTakeaway: "Lock your workstation every time you step away, and always route traffic via secure VPN."
      },
      {
        id: 5,
        title: "IT Security Wrap-up & Final Slide",
        subtitle: "You have verified your cybersecurity readiness",
        badge: "FINAL SLIDE • READY TO UNLOCK COURSE 4",
        content: "You have reached the final slide of Course 3 (IT Security Guidelines)! Your understanding of cyber defense safeguards our entire organization.",
        bullets: [
          "Completed: Password protocols, MFA enforcement, and credential confidentiality.",
          "Mastered: Phishing detection, email safety, and suspicious message escalation.",
          "Understood: Workstation locking, company VPN requirements, and device hygiene.",
          "Next Up in Course 4: Department Introduction and Organizational Structure."
        ],
        callout: {
          type: "checklist",
          text: "Click 'Complete & Unlock Course 4' below to finalize Course 3 and unlock Course 4."
        },
        keyTakeaway: "Course 3 complete! Course 4 (Department Introduction) is now unlocked."
      }
    ]
  },
  {
    id: 4,
    title: "Department Introduction",
    subtitle: "Team hierarchy, ongoing initiatives & key collaboration points",
    type: "video",
    category: "ORGANIZATION",
    duration: "15 min",
    duration_minutes: 15,
    video_url: "https://www.youtube.com/embed/ysz5S6PUM-U",
    roadmap_day: 1,
    gradient: "from-purple-700 via-indigo-900 to-slate-950",
    accentGlow: "rgba(147, 51, 234, 0.4)",
    tagline: "TEAM STRUCTURE & IMPACT",
    coverLabel: "DEPARTMENT INTRO",
    theme: "team",
    slides: [
      {
        id: 1,
        title: "Meet Your Department & Teams",
        subtitle: "How our organization is structured for agility",
        badge: "ORGANIZATION • SLIDE 1 OF 5",
        content: "HexaLearn operates through specialized, cross-functional squads designed to deliver projects with high speed and zero bureaucracy.",
        bullets: [
          "Engineering & Technology: Full-stack developers, frontend architects, LMS integration specialists, and QA engineers.",
          "Instructional Design & Content: Learning architects, curriculum planners, and pedagogical researchers.",
          "Visual & UI/UX Design: Graphic artists, motion animators, and design system leads.",
          "Project Delivery & Accounts: Scrum masters and project managers ensuring seamless client communication."
        ],
        callout: {
          type: "tip",
          text: "You can find your department directory and team roster inside the Timesheet portal."
        },
        keyTakeaway: "Collaboration across design, technology, and content is where our best work is born."
      },
      {
        id: 2,
        title: "Roles, Responsibilities & Hierarchy",
        subtitle: "Clear ownership, zero ambiguity",
        badge: "HIERARCHY • SLIDE 2 OF 5",
        content: "We emphasize flat hierarchy where leadership is accessible and decisions are driven by data and user empathy rather than job titles.",
        bullets: [
          "Reporting Manager: Your guide for career development, project allocation, and performance reviews.",
          "Technical Lead / Mentor: Your go-to partner for code architecture, technical questions, and code reviews.",
          "Peer Buddy: An experienced colleague paired with you for informal questions and social integration.",
          "Product Owner: Defines the 'Why' and 'What' of the sprint deliverables."
        ],
        callout: {
          type: "quote",
          text: "\"Great teams don't just share workspaces; they share purpose and mutual trust.\""
        },
        keyTakeaway: "Know who to turn to when you need guidance, feedback, or technical help."
      },
      {
        id: 3,
        title: "Agile Ceremonies & Communication",
        subtitle: "Staying in sync every day",
        badge: "AGILE RHYTHM • SLIDE 3 OF 5",
        content: "We run on two-week sprints designed to provide steady cadence and eliminate last-minute rushes.",
        bullets: [
          "Daily Standup (15 mins): Yesterday's accomplishments, today's goals, and any blockers.",
          "Sprint Planning: Review user stories, estimate complexity, and commit to sprint scope.",
          "Sprint Review & Demo: Showcase working software and interactive modules to stakeholders.",
          "Sprint Retrospective: Discuss what went well, what can be improved, and continuous tweaks."
        ],
        callout: {
          type: "tip",
          text: "Keep standups concise: focus on blockers so the team can unblock you quickly."
        },
        keyTakeaway: "Agile ceremonies keep our teams aligned, focused, and free from bottlenecks."
      },
      {
        id: 4,
        title: "Cross-Department Collaboration & Escalation",
        subtitle: "Getting answers when you are stuck",
        badge: "SUPPORT • SLIDE 4 OF 5",
        content: "When you encounter roadblocks that impact sprint delivery or cross-team dependencies, use our clear escalation matrix.",
        bullets: [
          "Step 1: Communicate with your immediate mentor or pairing buddy within 2 hours.",
          "Step 2: If unresolved by midday, raise the blocker during daily sync with your Lead.",
          "Step 3: Engineering managers and department heads step in to remove technical or administrative bottlenecks."
        ],
        interactiveCheck: {
          question: "What should you do if you are blocked on a task for more than 2 hours?",
          options: [
            "Wait silently until the sprint ends",
            "Reach out to your mentor, buddy, or team lead on Slack to get unblocked",
            "Delete the code and start over",
            "Ignore the task completely"
          ],
          correctIndex: 1,
          explanation: "Always communicate early! Reaching out to your mentor or lead quickly keeps the project moving smoothly."
        },
        keyTakeaway: "Never struggle in silence; asking for help early is a sign of high maturity."
      },
      {
        id: 5,
        title: "Department Introduction Wrap-up & Final Slide",
        subtitle: "Ready to explore Workplace Health & Safety",
        badge: "FINAL SLIDE • READY TO UNLOCK COURSE 5",
        content: "You have reached the final slide of Course 4 (Department Introduction)! You now understand our team organization, agile rhythm, and collaboration culture.",
        bullets: [
          "Completed: Department structure, roles, and cross-functional teams.",
          "Learned: Daily standup etiquette, sprint rhythm, and escalation channels.",
          "Next Up in Course 5: Workplace Health & Safety Standards (ergonomics, emergency exits, and well-being)."
        ],
        callout: {
          type: "checklist",
          text: "Click 'Complete & Unlock Course 5' below to finalize Course 4 and unlock Course 5."
        },
        keyTakeaway: "Course 4 complete! Course 5 (Workplace Health & Safety) is now unlocked."
      }
    ]
  },
  {
    id: 5,
    title: "Workplace Health & Safety",
    subtitle: "Ergonomics, emergency readiness & physical wellbeing standards",
    type: "document",
    category: "SAFETY & ERGONOMICS",
    duration: "15 min",
    duration_minutes: 15,
    roadmap_day: 1,
    gradient: "from-amber-600 via-orange-850 to-slate-950",
    accentGlow: "rgba(245, 158, 11, 0.4)",
    tagline: "WELLBEING & INCIDENT RESPONSE",
    coverLabel: "HEALTH & SAFETY",
    theme: "safety",
    slides: [
      {
        id: 1,
        title: "Your Physical Well-being & Workplace Safety",
        subtitle: "Health comes first, always",
        badge: "SAFETY • SLIDE 1 OF 5",
        content: "A safe, clean, and ergonomic workplace is the foundation of long-term professional health. Whether working from our physical campus or your home office, health guidelines are paramount.",
        bullets: [
          "Zero-tolerance for unsafe working conditions or ignored safety hazards.",
          "Clear physical protocols for ergonomics, trip hazards, and electrical safety.",
          "Immediate first-aid readiness with certified health representatives."
        ],
        callout: {
          type: "important",
          text: "Report any loose cables, broken fixtures, or spillage to facilities management immediately."
        },
        keyTakeaway: "Your physical health and safety are our highest priorities."
      },
      {
        id: 2,
        title: "Computer Ergonomics & Desk Setup",
        subtitle: "Preventing repetitive strain and eye fatigue",
        badge: "ERGONOMICS • SLIDE 2 OF 5",
        content: "Long hours spent at monitors require intentional ergonomic adjustments to prevent musculoskeletal strain.",
        bullets: [
          "Monitor Position: Top third of the screen should be at eye level, roughly an arm's length away.",
          "Chair & Posture: Back supported, knees at a 90-degree angle, feet flat on the floor or footrest.",
          "Wrists & Forearms: Keep wrists in a neutral, relaxed position without resting hard edges.",
          "The 20-20-20 Rule: Every 20 minutes, look at an object 20 feet away for 20 seconds to prevent eye fatigue."
        ],
        callout: {
          type: "tip",
          text: "Tip: Adjust your chair lumbar support and stand up for a 2-minute walk every hour."
        },
        keyTakeaway: "Proper workstation posture and hourly micro-breaks protect your spine and eyesight."
      },
      {
        id: 3,
        title: "Emergency Evacuation & Fire Safety",
        subtitle: "Knowing what to do when alarms sound",
        badge: "EMERGENCY PROTOCOLS • SLIDE 3 OF 5",
        content: "In the unlikely event of an emergency, clear knowledge of exit paths saves lives.",
        bullets: [
          "Emergency Exits: Marked with glowing green exit signage; never block emergency stairwells or doors.",
          "Fire Extinguishers: Located on each floor next to the main elevator bank and utility rooms.",
          "Assembly Point: Located at the primary open ground outside the main reception gates.",
          "Evacuation Rule: Never use elevators during a fire alarm; always use marked emergency staircases."
        ],
        callout: {
          type: "checklist",
          text: "Familiarize yourself with the floor evacuation map posted near your desk."
        },
        keyTakeaway: "In an evacuation, remain calm, leave heavy items behind, and proceed to the designated assembly point."
      },
      {
        id: 4,
        title: "First Aid & Medical Emergency Contacts",
        subtitle: "Rapid response when assistance is needed",
        badge: "FIRST AID • SLIDE 4 OF 5",
        content: "HexaLearn maintains fully stocked medical kits on every floor and designated first aid officers.",
        bullets: [
          "First Aid Boxes: Contain bandages, antiseptic wipes, burn ointment, and basic OTC medical supplies.",
          "Campus Helpline: Extension #911 on internal desk phones connects directly to the emergency team.",
          "Paramedic Network: Hospital tie-ups provide rapid ambulance dispatch within 10 minutes.",
          "Incident Reporting: Any workplace injury, however small, must be logged with HR for recordkeeping."
        ],
        interactiveCheck: {
          question: "What is the recommended rule to prevent screen eye strain?",
          options: [
            "Stare at the monitor without blinking for 8 hours",
            "The 20-20-20 Rule (every 20 mins, look 20 feet away for 20 seconds)",
            "Turn off the room lights completely and work in total darkness",
            "Never take breaks during the day"
          ],
          correctIndex: 1,
          explanation: "The 20-20-20 rule gives your eye muscles time to relax and prevents digital eye strain."
        },
        keyTakeaway: "Know the emergency contacts and report any physical injuries immediately."
      },
      {
        id: 5,
        title: "Health & Safety Wrap-up & Final Slide",
        subtitle: "Ready to proceed to Data Privacy & GDPR",
        badge: "FINAL SLIDE • READY TO UNLOCK COURSE 6",
        content: "You have completed the essential workplace health, ergonomics, and emergency readiness guidelines in Course 5!",
        bullets: [
          "Completed: Computer ergonomics and 20-20-20 eye wellness habits.",
          "Reviewed: Emergency evacuation stairs, assembly points, and fire safety protocols.",
          "Next Up in Course 6: Data Privacy, Confidentiality & GDPR compliance."
        ],
        callout: {
          type: "checklist",
          text: "Click 'Complete & Unlock Course 6' below to mark Course 5 complete and unlock Course 6."
        },
        keyTakeaway: "Course 5 complete! Course 6 (Data Privacy & GDPR) is now unlocked."
      }
    ]
  },
  {
    id: 6,
    title: "Tools & Development Setup",
    subtitle: "Git workflows, Slack channels, corporate mail & timesheet logging",
    type: "document",
    category: "DEV TOOLS & STACK",
    duration: "20 min",
    duration_minutes: 20,
    roadmap_day: 1,
    gradient: "from-sky-600 via-blue-800 to-slate-950",
    accentGlow: "rgba(14, 165, 233, 0.4)",
    tagline: "ENGINEERING PRODUCTIVITY",
    coverLabel: "TOOLS & WORKFLOW",
    theme: "tools",
    slides: [
      {
        id: 1,
        title: "Data Privacy & Legal Compliance",
        subtitle: "Handling sensitive information with high ethical standards",
        badge: "DATA PRIVACY • SLIDE 1 OF 5",
        content: "HexaLearn operates in compliance with international data privacy laws including the General Data Protection Regulation (GDPR) and local data privacy frameworks.",
        bullets: [
          "Every individual has a fundamental legal right to data privacy and digital protection.",
          "Breaches of confidentiality carry severe legal, financial, and reputational consequences.",
          "We handle client data with the highest classification of care and non-disclosure covenants."
        ],
        callout: {
          type: "important",
          text: "Client data must never be copied to personal USB drives, public Google Drives, or third-party AI tools without approval."
        },
        keyTakeaway: "Confidentiality is an uncompromising legal covenant in every contract we sign."
      },
      {
        id: 2,
        title: "Classifying Data: Public, Internal & Restricted",
        subtitle: "Knowing what requires highest protection",
        badge: "CLASSIFICATION • SLIDE 2 OF 5",
        content: "To safeguard information properly, you must understand how data is categorized at HexaLearn.",
        bullets: [
          "Public Data: Marketing materials, public blog posts, published whitepapers.",
          "Internal Data: Company announcements, internal handbooks, department guidelines.",
          "Confidential / PII: Personal Identifiable Information (employee salaries, emails, client learner names).",
          "Restricted / Secret: Client source code, database production credentials, proprietary algorithms."
        ],
        callout: {
          type: "tip",
          text: "Always redact Personally Identifiable Information (PII) before sharing test screenshots or demo data."
        },
        keyTakeaway: "Treat all client code, database backups, and personal data as strictly Restricted."
      },
      {
        id: 3,
        title: "GDPR Core Principles in eLearning",
        subtitle: "How privacy affects our day-to-day platforms",
        badge: "GDPR PRINCIPLES • SLIDE 3 OF 5",
        content: "When developing eLearning modules, SCORM packages, and learning tracking engines, GDPR dictates strict architectural rules.",
        bullets: [
          "Data Minimization: Collect only the learner telemetry needed for course progress (e.g. completion status, score).",
          "Purpose Limitation: Data collected for course tracking cannot be repurposed for external analytics.",
          "Right to Erasure ('Right to be Forgotten'): Architecture must support anonymization upon user request.",
          "Secure Storage & Encryption: Learner records and passwords must be encrypted in transit (TLS 1.3) and at rest (AES-256)."
        ],
        callout: {
          type: "checklist",
          text: "Never store plaintext passwords or unmasked student tokens in browser localStorage or URLs."
        },
        keyTakeaway: "Privacy by design must be embedded into every module, API, and database table we build."
      },
      {
        id: 4,
        title: "Clean Desk, Clean Screen & AI Policy",
        subtitle: "Modern guidelines for code and document safety",
        badge: "PRACTICAL GUIDELINES • SLIDE 4 OF 5",
        content: "With the rise of generative AI and remote work, protecting intellectual property requires mindful daily habits.",
        bullets: [
          "Generative AI Caution: Never paste proprietary client source code or contracts into public ChatGPT/Claude models.",
          "Clean Desk Principle: Physical paper notes with passwords or project architecture must be shredded.",
          "Clean Screen: Never screen-share production database credentials or confidential client emails on public meetings.",
          "Reporting Data Spills: If confidential data is accidentally emailed to the wrong party, notify legal immediately."
        ],
        interactiveCheck: {
          question: "Can proprietary client source code be pasted into public, unvetted AI chat tools?",
          options: [
            "Yes, without any restrictions",
            "No, never paste confidential client code into public AI tools without corporate approval",
            "Only on weekends",
            "Only if nobody is watching"
          ],
          correctIndex: 1,
          explanation: "Never paste proprietary client source code or sensitive documents into public AI tools."
        },
        keyTakeaway: "Protect intellectual property with utmost discretion and respect for client agreements."
      },
      {
        id: 5,
        title: "Data Privacy Wrap-up & Final Slide",
        subtitle: "Ready to proceed to Diversity, Equity & Inclusion",
        badge: "FINAL SLIDE • READY TO UNLOCK COURSE 7",
        content: "You have reviewed the core data privacy covenants and GDPR requirements for HexaLearn deliverables in Course 6!",
        bullets: [
          "Completed: Information classification (Public, Internal, Confidential, Restricted).",
          "Mastered: GDPR core principles, data minimization, and learner privacy safeguards.",
          "Understood: Intellectual property protection, clean screen habits, and responsible AI usage.",
          "Next Up in Course 7: Diversity, Equity & Inclusion (DEI) and workplace culture."
        ],
        callout: {
          type: "checklist",
          text: "Click 'Complete & Unlock Course 7' below to finalize Course 6 and unlock Course 7."
        },
        keyTakeaway: "Course 6 complete! Course 7 (Diversity, Equity & Inclusion) is now unlocked."
      }
    ]
  },
  {
    id: 7,
    title: "Code of Conduct & Ethics",
    subtitle: "Professional integrity, anti-harassment policy & business ethics",
    type: "document",
    category: "PROFESSIONAL ETHICS",
    duration: "20 min",
    duration_minutes: 20,
    roadmap_day: 1,
    gradient: "from-violet-700 via-purple-900 to-slate-950",
    accentGlow: "rgba(109, 40, 217, 0.4)",
    tagline: "STANDARDS & INTEGRITY",
    coverLabel: "CODE OF CONDUCT",
    theme: "ethics",
    slides: [
      {
        id: 1,
        title: "Diversity, Equity & Inclusion at HexaLearn",
        subtitle: "Our strength lies in our differences",
        badge: "DEI • SLIDE 1 OF 5",
        content: "At HexaLearn, we believe diverse perspectives spark innovation and creativity. We are committed to cultivating an inclusive workplace where every teammate feels welcomed, valued, and respected.",
        bullets: [
          "Diversity: Representing people across different genders, cultures, races, abilities, and life backgrounds.",
          "Equity: Providing equal opportunities, fair compensation, and personalized pathways for growth.",
          "Inclusion: Ensuring everyone's voice is heard, invited to the discussion, and genuinely valued."
        ],
        callout: {
          type: "quote",
          text: "\"Diversity is being invited to the party; inclusion is being asked to dance.\" — Verna Myers"
        },
        keyTakeaway: "We thrive because we celebrate each individual's authentic background and unique ideas."
      },
      {
        id: 2,
        title: "Inclusive Language & Communication",
        subtitle: "How everyday words shape our team environment",
        badge: "COMMUNICATION • SLIDE 2 OF 5",
        content: "The words we use in Slack messages, pull requests, and meetings have a profound impact on belonging and team morale.",
        bullets: [
          "Gender-Neutral Phrasing: Use 'Hello everyone' or 'Hi team' rather than 'Hey guys'.",
          "Constructive Code Reviews: Critique the code, not the person. Use 'Could we consider X here?' instead of 'Why did you do this?'.",
          "Active Listening: Allow colleagues to finish speaking without interrupting during sprint meetings.",
          "Accessibility in Content: Design learning modules with closed captions, high contrast, and screen-reader support."
        ],
        callout: {
          type: "tip",
          text: "Kindness and clarity in written communication build lasting trust across remote and global teams."
        },
        keyTakeaway: "Empathetic communication turns a group of individuals into a united team."
      },
      {
        id: 3,
        title: "Recognizing & Addressing Unconscious Bias",
        subtitle: "Mindful decision-making in hiring and teamwork",
        badge: "BIAS AWARENESS • SLIDE 3 OF 5",
        content: "Unconscious biases are mental shortcuts that everyone has. Becoming conscious of them allows us to make fairer decisions.",
        bullets: [
          "Affinity Bias: Gravitating toward people who share your educational background, hometown, or hobbies.",
          "Confirmation Bias: Seeking information that confirms preconceived beliefs while ignoring alternative data.",
          "Performance Bias: Assuming competence or lack thereof based on stereotypes rather than observed work output.",
          "Interrupting Bias: Base hiring, promotions, and peer feedback on clear, objective criteria and metrics."
        ],
        callout: {
          type: "checklist",
          text: "Ask yourself: 'Would I give this same feedback if the person had a different background?'"
        },
        keyTakeaway: "Awareness of bias helps us build a truly merit-based, fair environment."
      },
      {
        id: 4,
        title: "Allyship & Psychological Safety",
        subtitle: "Standing up for each other every day",
        badge: "ALLYSHIP • SLIDE 4 OF 5",
        content: "Psychological safety is the shared belief that team members will not be punished or humiliated for speaking up with ideas, questions, concerns, or mistakes.",
        bullets: [
          "Amplify Voices: When someone shares a good point that gets overlooked, bring the group's focus back to acknowledge them.",
          "Normalize Mistakes: Treat bugs and deployment slips as team learning opportunities, not blame games.",
          "Zero-Tolerance for Microaggressions: Subtle disparaging comments or exclusionary behavior will not be tolerated.",
          "Supportive HR Channels: Safe, confidential escalation paths are always available to any team member."
        ],
        interactiveCheck: {
          question: "What is an effective example of practicing allyship during a team meeting?",
          options: [
            "Interrupting quieter teammates continuously",
            "Amplifying a quiet colleague's idea and ensuring their contribution is recognized",
            "Ignoring questions raised by junior engineers",
            "Leaving meetings without listening to others"
          ],
          correctIndex: 1,
          explanation: "Amplifying colleagues and ensuring everyone has space to contribute is the essence of active allyship."
        },
        keyTakeaway: "We all have the power and duty to make HexaLearn a welcoming community."
      },
      {
        id: 5,
        title: "Diversity & Inclusion Wrap-up & Final Slide",
        subtitle: "Ready to proceed to Tools & Development Setup",
        badge: "FINAL SLIDE • READY TO UNLOCK COURSE 8",
        content: "You have completed the Diversity, Equity & Inclusion module in Course 7! Respect, belonging, and psychological safety are pillars of our workplace.",
        bullets: [
          "Explored: Principles of Diversity, Equity, and Inclusion at HexaLearn.",
          "Practiced: Inclusive language, constructive peer reviews, and unconscious bias mitigation.",
          "Committed: Active allyship, psychological safety, and mutual support.",
          "Next Up in Course 8: Tools & Development Setup (Git, Slack, timesheets, and IDE environment)."
        ],
        callout: {
          type: "checklist",
          text: "Click 'Complete & Unlock Course 8' below to finalize Course 7 and unlock Course 8."
        },
        keyTakeaway: "Course 7 complete! Course 8 (Tools & Development Setup) is now unlocked."
      }
    ]
  },
  {
    id: 8,
    title: "Diversity, Equity & Inclusion",
    subtitle: "Fostering an open, respectful, equitable and diverse community",
    type: "document",
    category: "CULTURE & DEI",
    duration: "15 min",
    duration_minutes: 15,
    roadmap_day: 1,
    gradient: "from-fuchsia-600 via-pink-700 to-slate-950",
    accentGlow: "rgba(217, 70, 239, 0.4)",
    tagline: "EQUALITY & BELONGING",
    coverLabel: "DIVERSITY & INCLUSION",
    theme: "dei",
    slides: [
      {
        id: 1,
        title: "Engineering Tools & Productivity Ecosystem",
        subtitle: "Equipping you with the right workbench",
        badge: "TECH STACK • SLIDE 1 OF 5",
        content: "A smooth developer experience powers high-velocity delivery. Our standardized tooling ensures seamless collaboration across all project repositories.",
        bullets: [
          "Version Control: Enterprise GitHub organization with protected master/main branches.",
          "Communication & Sync: Slack workspaces, Google Meet, and Jira/Linear sprint tracking.",
          "Code Quality & CI/CD: Automated linting (ESLint, Prettier), PHPUnit, and GitHub Actions pipelines.",
          "Internal Portals: Hex-Timesheet, KnowledgeHub, and this Onboarding portal."
        ],
        callout: {
          type: "tip",
          text: "Bookmark your internal portal link: https://corhis.hexalearn.com for single sign-on access."
        },
        keyTakeaway: "Our tools are calibrated to keep your daily focus on building impactful solutions."
      },
      {
        id: 2,
        title: "Git Workflow, Branching & Pull Requests",
        subtitle: "Clean commits and smooth code reviews",
        badge: "VERSION CONTROL • SLIDE 2 OF 5",
        content: "We adhere strictly to feature-branch workflows to maintain zero regressions in production code.",
        bullets: [
          "Branch Naming: Use descriptive prefixes: feature/onboard-quiz, fix/login-session, chore/bump-deps.",
          "Atomic Commits: Write concise, imperative commit messages (e.g. 'Add 10-course progression slider').",
          "Pull Request Etiquette: Include a clear description, screenshots of UI changes, and test instructions.",
          "Review Requirements: Minimum 1 peer approval required before merging into develop or main."
        ],
        callout: {
          type: "important",
          text: "Never commit secrets, API keys, or database credentials to git. Use .env files with .gitignore."
        },
        keyTakeaway: "Clean branching habits and descriptive pull requests protect code stability."
      },
      {
        id: 3,
        title: "Slack Channels & Team Etiquette",
        subtitle: "Effective asynchronous communication",
        badge: "COLLABORATION • SLIDE 3 OF 5",
        content: "Slack is our primary virtual office. Mindful communication keeps noise low and signal high.",
        bullets: [
          "#announcements: Company-wide updates from leadership and HR (read-only for general chatter).",
          "#team-[dept]: Your department's dedicated daily workspace and sprint coordination.",
          "#watercooler: Casual conversation, celebrations, pet photos, and weekend plans.",
          "Thread Usage: Always reply in threads to keep main channel feeds scannable and organized."
        ],
        callout: {
          type: "tip",
          text: "Update your Slack status (In a meeting, Lunch, OOO, Heads down coding) to set clear expectations."
        },
        keyTakeaway: "Use threads to organize discussions and keep notification distractions low."
      },
      {
        id: 4,
        title: "Daily Timesheet Logging in Hex-Timesheet",
        subtitle: "Recording your daily achievements accurately",
        badge: "TIMESHEET • SLIDE 4 OF 5",
        content: "Accurate timesheet entries ensure precise client billing, transparent sprint accounting, and balanced project resource planning.",
        bullets: [
          "Daily Entry: Log your hours at the end of each working day before logging off.",
          "Project Selection: Select the correct client code and task category from the dropdown.",
          "Task Summary: Write a clear sentence detailing what was built or reviewed.",
          "Weekend Submissions: Friday afternoon is the final cutoff for submitting your weekly timesheet block."
        ],
        interactiveCheck: {
          question: "When should your daily timesheet hours be logged?",
          options: [
            "Once every six months",
            "At the end of each working day before logging off",
            "Never; timesheets are optional",
            "Only when an emergency occurs"
          ],
          correctIndex: 1,
          explanation: "Timesheets should be submitted daily at the end of each working day to maintain accurate project records."
        },
        keyTakeaway: "Timely timesheet logging is a professional habit expected of every teammate."
      },
      {
        id: 5,
        title: "Tools Setup Wrap-up & Final Slide",
        subtitle: "Ready to proceed to Code of Conduct & Ethics",
        badge: "FINAL SLIDE • READY TO UNLOCK COURSE 9",
        content: "You have verified your engineering tools, Git workflows, Slack communication channels, and timesheet logging!",
        bullets: [
          "Completed: GitHub enterprise branch rules, commit standards, and pull request reviews.",
          "Mastered: Slack channel etiquette, thread hygiene, and status management.",
          "Understood: Daily timesheet entries inside the Hex-Timesheet portal.",
          "Next Up in Course 9: Code of Conduct & Workplace Ethics."
        ],
        callout: {
          type: "checklist",
          text: "Click 'Complete & Unlock Course 9' below to finalize Course 8 and unlock Course 9."
        },
        keyTakeaway: "Course 8 complete! Course 9 (Code of Conduct & Ethics) is now unlocked."
      }
    ]
  },
  {
    id: 9,
    title: "Time Tracking, Timesheet & Project Logging",
    subtitle: "Track hours, manage projects & maintain productivity",
    type: "document",
    category: "TIMESHEET & TRACKING",
    duration: "20 min",
    duration_minutes: 20,
    roadmap_day: 1,
    gradient: "from-purple-600 via-indigo-850 to-slate-950",
    accentGlow: "rgba(147, 51, 234, 0.4)",
    tagline: "TIMESHEET & PRODUCTIVITY",
    coverLabel: "TIMESHEET LOGGING",
    theme: "video",
    slides: [
      {
        id: 1,
        title: "Code of Conduct & Professional Integrity",
        subtitle: "The gold standard of ethical conduct",
        badge: "ETHICS • SLIDE 1 OF 5",
        content: "HexaLearn has earned the trust of clients and employees through honesty, accountability, and fair business practices. Our Code of Conduct sets clear boundaries for professional behavior.",
        bullets: [
          "Honesty in all representations, client deliverables, and peer interactions.",
          "Zero-tolerance for fraud, bribery, kickbacks, or deceptive reporting.",
          "Commitment to ethical artificial intelligence and responsible technology usage."
        ],
        callout: {
          type: "important",
          text: "Every employee represents HexaLearn. Your conduct reflects on the entire company."
        },
        keyTakeaway: "Act with honor and integrity in every decision you make."
      },
      {
        id: 2,
        title: "Anti-Harassment & POSH Compliance",
        subtitle: "A completely safe, dignified workspace for everyone",
        badge: "ZERO HARASSMENT • SLIDE 2 OF 5",
        content: "We enforce strict zero-tolerance policies against any form of harassment, discrimination, or intimidation—online or offline.",
        bullets: [
          "Prevention of Sexual Harassment (POSH): Comprehensive safeguards and an Internal Complaints Committee (ICC).",
          "Bullying & Hostile Behavior: Shouting, belittling comments, or public humiliation will result in immediate disciplinary action.",
          "Retaliation Protection: Anyone reporting a genuine concern in good faith is protected by strict anti-retaliation rules.",
          "Confidential Redressal: All reports are investigated with promptness, fairness, and complete confidentiality."
        ],
        callout: {
          type: "checklist",
          text: "You can reach out directly to icc@hexalearn.com or the HR head for confidential support."
        },
        keyTakeaway: "Every employee has the inviolable right to work free from harassment."
      },
      {
        id: 3,
        title: "Conflicts of Interest & Moonlighting",
        subtitle: "Protecting your commitment and company interests",
        badge: "BUSINESS ETHICS • SLIDE 3 OF 5",
        content: "Employees must avoid situations where personal interests conflict with their professional obligations to HexaLearn.",
        bullets: [
          "Secondary Employment / Moonlighting: Engaging in external commercial work or consulting for competing firms is prohibited.",
          "Gifts & Hospitality: Never accept expensive gifts, kickbacks, or personal favors from vendors or clients.",
          "Personal Relationships: Disclose any pre-existing commercial relationships with prospective suppliers or subcontractors."
        ],
        callout: {
          type: "tip",
          text: "When uncertain if a situation constitutes a conflict of interest, proactively seek guidance from HR."
        },
        keyTakeaway: "Transparency in potential conflicts safeguards both you and the organization."
      },
      {
        id: 4,
        title: "Whistleblower Protections & Reporting",
        subtitle: "Speaking up when something isn't right",
        badge: "SPEAKING UP • SLIDE 4 OF 5",
        content: "If you witness financial malpractice, safety violations, or serious ethical breaches, you have multiple safe avenues to speak up.",
        bullets: [
          "Whistleblower Channel: Dedicated, secure email managed directly by leadership ethics officers.",
          "Anonymous Reporting: Reports can be submitted anonymously through internal ethics drops.",
          "Thorough Investigation: All substantiated complaints undergo independent fact-finding.",
          "Protection from Retaliation: Retaliation against any whistleblower is treated as a severe disciplinary violation."
        ],
        interactiveCheck: {
          question: "What should you do if you observe a serious ethical violation or harassment?",
          options: [
            "Ignore it and hope it goes away",
            "Report it confidentially to HR, the ICC, or via the Whistleblower channel",
            "Share gossip about it on social media",
            "Encourage others to break rules too"
          ],
          correctIndex: 1,
          explanation: "Reporting concerns confidentially to HR or the ethics committee ensures the issue is resolved fairly."
        },
        keyTakeaway: "Speaking up protects our community; leadership will always support your courage."
      },
      {
        id: 5,
        title: "Ethics Wrap-up & Final Slide",
        subtitle: "Ready to unlock Course 10: Final Induction Wrap-Up",
        badge: "FINAL SLIDE • READY TO UNLOCK COURSE 10",
        content: "You have reviewed our high standards for professional integrity, anti-harassment policies, and business ethics in Course 9!",
        bullets: [
          "Completed: Professional integrity standards and company representation.",
          "Mastered: POSH anti-harassment protections and confidential grievance procedures.",
          "Affirmed: Whistleblower protections and zero-tolerance for retaliation.",
          "Next Up in Course 10: Final Induction Wrap-Up & Assessment Readiness!"
        ],
        callout: {
          type: "checklist",
          text: "Click 'Complete & Unlock Course 10' below to finalize Course 9 and unlock Course 10."
        },
        keyTakeaway: "Course 9 complete! Course 10 (Final Induction Wrap-Up) is now unlocked."
      }
    ]
  },
  {
    id: 10,
    title: "Career Pathways, Performance & Annual Review",
    subtitle: "Growth opportunities, performance reviews & career development",
    type: "document",
    category: "CAREER & GROWTH",
    duration: "20 min",
    duration_minutes: 20,
    roadmap_day: 1,
    gradient: "from-blue-600 via-indigo-800 to-slate-950",
    accentGlow: "rgba(59, 130, 246, 0.4)",
    tagline: "CAREER & DEVELOPMENT",
    coverLabel: "CAREER PATHWAYS",
    theme: "graduation",
    slides: [
      {
        id: 1,
        title: "Congratulations on Reaching Course 10!",
        subtitle: "You are at the final milestone of your induction journey",
        badge: "FINAL COURSE • SLIDE 1 OF 5",
        content: "You have journeyed through 9 comprehensive courses covering our culture, policies, IT security, engineering workflows, and ethical standards. Course 10 brings it all together.",
        bullets: [
          "Courses 1 to 9 successfully mastered and completed.",
          "You have built a deep foundational understanding of how HexaLearn works and thrives.",
          "Only this final module stands between you and unlocking your Final Onboarding Assessment!"
        ],
        callout: {
          type: "quote",
          text: "\"Learning is not attained by chance, it must be sought for with ardor and attended to with diligence.\" — Abigail Adams"
        },
        keyTakeaway: "Celebrate how much you've absorbed; you are ready for impactful contributions."
      },
      {
        id: 2,
        title: "Retrospective: 10 Induction Milestones",
        subtitle: "A quick recap of your learning path",
        badge: "RETROSPECTIVE • SLIDE 2 OF 5",
        content: "Let's review the critical concepts you've learned across our induction curriculum:",
        bullets: [
          "Orientation & Handbook: Company heritage, core values, working hours, leaves, and healthcare benefits.",
          "Safety & Security: Cybersecurity hygiene, MFA, phishing defense, workstation locking, and ergonomics.",
          "Compliance & DEI: GDPR privacy guidelines, inclusive communication, and anti-harassment protections.",
          "Engineering & Ethics: Git workflows, Slack thread etiquette, daily timesheet logging, and Code of Conduct."
        ],
        callout: {
          type: "tip",
          text: "All 10 modules remain open for review anytime if you wish to revisit slide notes."
        },
        keyTakeaway: "These 10 pillars empower you to work safely, confidently, and effectively."
      },
      {
        id: 3,
        title: "Your 30-60-90 Day Growth Blueprint",
        subtitle: "From orientation to high-impact leadership",
        badge: "ROADMAP • SLIDE 3 OF 5",
        content: "Your onboarding doesn't end today; it transitions into structured milestones over the next three months:",
        bullets: [
          "Days 1 – 30 (Orientation & Integration): Master tools, complete this onboarding program, build relationships, and deliver your first sprint tasks.",
          "Days 31 – 60 (Autonomy & Collaboration): Own full user stories, lead code reviews, and suggest architecture enhancements.",
          "Days 61 – 90 (Impact & Mentorship): Deliver key milestones independently, contribute to cross-functional reviews, and help onboard future teammates."
        ],
        callout: {
          type: "checklist",
          text: "Schedule your 30-day review check-in with your manager to celebrate early wins."
        },
        keyTakeaway: "Pace yourself, seek continuous feedback, and celebrate your progression."
      },
      {
        id: 4,
        title: "Final Assessment Overview & Instructions",
        subtitle: "What to expect in your comprehensive final test",
        badge: "ASSESSMENT PREP • SLIDE 4 OF 5",
        content: "Following the completion of this slide deck, your Final Onboarding Assessment will be unlocked automatically!",
        bullets: [
          "Format: 10 comprehensive multiple-choice questions covering all induction courses.",
          "Passing Score: 75% or higher (at least 8 out of 10 questions correct).",
          "Attempts: Multiple attempts permitted if you need to review and retake.",
          "Certificate: Upon passing, your official Onboarding Completion Certificate is granted!"
        ],
        interactiveCheck: {
          question: "When does your Final Onboarding Assessment unlock?",
          options: [
            "Never; it is permanently locked",
            "Automatically as soon as Course 10's final slide is completed",
            "After 10 years of service",
            "Only on a full moon"
          ],
          correctIndex: 1,
          explanation: "Completing Course 10's final slide unlocks your Final Assessment immediately!"
        },
        keyTakeaway: "You are fully prepared; take your time and read each question carefully."
      },
      {
        id: 5,
        title: "Induction Wrap-up & Final Slide",
        subtitle: "All 10 courses complete! Final Assessment Unlocked!",
        badge: "CONGRATULATIONS • ALL COURSES COMPLETED",
        content: "🎉 Incredible work! You have reached the final slide of Course 10. By clicking the button below, all 10 induction courses will be marked 100% Complete, and your Final Assessment will be instantly unlocked!",
        bullets: [
          "All 10 Induction Courses completed.",
          "All course slide milestones verified.",
          "Final Assessment is now UNLOCKED and ready for you to take."
        ],
        callout: {
          type: "checklist",
          text: "Click 'Complete All Courses & Unlock Final Assessment' to graduate to the Final Assessment!"
        },
        keyTakeaway: "Congratulations! You are now eligible to take your Final Onboarding Assessment."
      }
    ]
  }
];
