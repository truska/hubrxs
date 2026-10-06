-- Approval recipients now come dynamically from enabled, non-archived
-- hub_user records with role = 'super_admin'. No new preferences are needed.
-- Retire the unused single-recipient preference if previously installed.
UPDATE cms_preferences
SET archived = 1, showoncms = 'No', showonweb = 'No'
WHERE name = 'prefUserApprovalEmail';
