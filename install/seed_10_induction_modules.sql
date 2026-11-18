-- HexaLearn Onboarding: Seed 10 Induction Modules
-- You can run this SQL in phpMyAdmin or MySQL console to populate the 10 modules in `onboard_modules`.

INSERT INTO `onboard_modules` (`id`, `title`, `type`, `description`, `duration_minutes`, `video_url`, `file_url`, `roadmap_day`, `sort_order`, `is_active`)
VALUES
(1, 'Welcome & Company Overview', 'video', 'Welcome to HexaLearn. An introduction to company leadership, mission, and core values.', 15, 'https://www.youtube.com/embed/dQw4w9WgXcQ', NULL, 1, 1, 1),
(2, 'Company Handbook', 'pdf', 'HexaLearn comprehensive employee handbook outlining policies, employee rights, leaves, and working hours.', 30, NULL, 'https://hexalearn.com/internal/hex-timesheet/onboard/docs/handbook.pdf', 1, 2, 1),
(3, 'IT Security Guidelines', 'document', 'Key policies for password management, workstation locking, phishing awareness, and corporate network VPN safety.', 20, NULL, NULL, 1, 3, 1),
(4, 'Department Introduction', 'video', 'Overview of your department structure, key team contacts, ongoing initiatives, and reporting hierarchy.', 25, 'https://www.youtube.com/embed/ysz5S6PUM-U', NULL, 1, 4, 1),
(5, 'Workplace Health & Safety Standards', 'document', 'Office ergonomics, emergency exit protocols, incident reporting, and maintaining physical health at work.', 15, NULL, NULL, 1, 5, 1),
(6, 'Data Privacy, Confidentiality & GDPR', 'document', 'Client confidentiality agreements, handling proprietary source code and intellectual property with care.', 20, NULL, NULL, 1, 6, 1),
(7, 'HexaLearn Culture, Values & Diversity', 'document', 'Our commitment to inclusion, diversity, mutual respect, constructive teamwork, and continuous learning.', 15, NULL, NULL, 1, 7, 1),
(8, 'Tools & Development Environment Setup', 'document', 'Step-by-step onboarding guide for Git repositories, Slack/Teams communication channels, email client, and timesheets.', 30, NULL, NULL, 1, 8, 1),
(9, 'Code of Conduct & Workplace Ethics', 'document', 'Professional standards, anti-harassment policy, escalation matrix, and business ethics.', 20, NULL, NULL, 1, 9, 1),
(10, 'Final Induction Wrap-up & Assessment Readiness', 'document', 'Wrap-up of your induction orientation and preparation guidelines for the onboarding knowledge assessments.', 25, NULL, NULL, 1, 10, 1)
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`),
  `type` = VALUES(`type`),
  `description` = VALUES(`description`),
  `duration_minutes` = VALUES(`duration_minutes`),
  `video_url` = VALUES(`video_url`),
  `file_url` = VALUES(`file_url`),
  `roadmap_day` = VALUES(`roadmap_day`),
  `sort_order` = VALUES(`sort_order`),
  `is_active` = VALUES(`is_active`);
