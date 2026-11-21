-- Zweite Site: Wurzelseite 100 und drei Unterseiten. Idempotent (INSERT OR IGNORE).
INSERT OR IGNORE INTO pages (uid, pid, title, slug, doktype, is_siteroot, sorting, crdate, tstamp, perms_userid, perms_groupid, perms_user, perms_group, perms_everybody)
VALUES
(100, 0,   'Zweite Website',  '/',            1, 1, 256,  strftime('%s','now'), strftime('%s','now'), 1, 0, 31, 27, 0),
(101, 100, 'Impressum',       '/impressum',   1, 0, 256,  strftime('%s','now'), strftime('%s','now'), 1, 0, 31, 27, 0),
(102, 100, 'Datenschutz',     '/datenschutz', 1, 0, 512,  strftime('%s','now'), strftime('%s','now'), 1, 0, 31, 27, 0),
(103, 100, 'Barrierefreiheit','/barrierefreiheit', 1, 0, 768, strftime('%s','now'), strftime('%s','now'), 1, 0, 31, 27, 0);
