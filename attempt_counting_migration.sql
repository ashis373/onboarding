-- Migration to add is_draft column for attempt counting
-- This allows attempts to be created as drafts and only counted when submitted

-- Add is_draft column to onboard_assessment_attempts table
ALTER TABLE onboard_assessment_attempts 
ADD COLUMN is_draft TINYINT(1) NOT NULL DEFAULT 0 
COMMENT '1 if this is a draft attempt (not counted), 0 if submitted';

-- Update existing attempts to be non-draft (they were already submitted)
UPDATE onboard_assessment_attempts 
SET is_draft = 0 
WHERE submitted_at IS NOT NULL;

-- Optional: Clean up any orphaned draft attempts older than 24 hours
-- DELETE FROM onboard_assessment_attempts 
-- WHERE is_draft = 1 
-- AND started_at < DATE_SUB(NOW(), INTERVAL 24 HOUR);
