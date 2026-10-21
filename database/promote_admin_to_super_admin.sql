-- Run once on an existing installation after backing up the database.
-- This grants Super Admin access to the current account named "admin".
-- Sign in as admin and change its password immediately after running this.
UPDATE users
SET role = 'super_admin'
WHERE username = 'admin' AND role = 'admin';
