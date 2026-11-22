-- 18 Seiten mit dem Inhaltselement, eine je Kombination aus Teil, Überschriftenebene und Stand-Zeile.
-- Unter der Wurzelseite 1 der Hauptsite (13.4: main, 14.3: camino). Idempotent (INSERT OR REPLACE auf feste uids).
INSERT OR REPLACE INTO pages (uid, pid, title, slug, doktype, is_siteroot, sorting, crdate, tstamp, perms_userid, perms_groupid, perms_user, perms_group, perms_everybody)
VALUES
(301, 1, 'Erklärung komplett h2 mit Stand', '/erklaerung-komplett-h2-mit-stand', 1, 0, 77056, strftime('%s','now'), strftime('%s','now'), 1, 0, 31, 27, 0),
(302, 1, 'Erklärung komplett h2 ohne Stand', '/erklaerung-komplett-h2-ohne-stand', 1, 0, 77312, strftime('%s','now'), strftime('%s','now'), 1, 0, 31, 27, 0),
(303, 1, 'Erklärung komplett h3 mit Stand', '/erklaerung-komplett-h3-mit-stand', 1, 0, 77568, strftime('%s','now'), strftime('%s','now'), 1, 0, 31, 27, 0),
(304, 1, 'Erklärung komplett h3 ohne Stand', '/erklaerung-komplett-h3-ohne-stand', 1, 0, 77824, strftime('%s','now'), strftime('%s','now'), 1, 0, 31, 27, 0),
(305, 1, 'Erklärung komplett h4 mit Stand', '/erklaerung-komplett-h4-mit-stand', 1, 0, 78080, strftime('%s','now'), strftime('%s','now'), 1, 0, 31, 27, 0),
(306, 1, 'Erklärung komplett h4 ohne Stand', '/erklaerung-komplett-h4-ohne-stand', 1, 0, 78336, strftime('%s','now'), strftime('%s','now'), 1, 0, 31, 27, 0),
(307, 1, 'Erklärung maengel h2 mit Stand', '/erklaerung-maengel-h2-mit-stand', 1, 0, 78592, strftime('%s','now'), strftime('%s','now'), 1, 0, 31, 27, 0),
(308, 1, 'Erklärung maengel h2 ohne Stand', '/erklaerung-maengel-h2-ohne-stand', 1, 0, 78848, strftime('%s','now'), strftime('%s','now'), 1, 0, 31, 27, 0),
(309, 1, 'Erklärung maengel h3 mit Stand', '/erklaerung-maengel-h3-mit-stand', 1, 0, 79104, strftime('%s','now'), strftime('%s','now'), 1, 0, 31, 27, 0),
(310, 1, 'Erklärung maengel h3 ohne Stand', '/erklaerung-maengel-h3-ohne-stand', 1, 0, 79360, strftime('%s','now'), strftime('%s','now'), 1, 0, 31, 27, 0),
(311, 1, 'Erklärung maengel h4 mit Stand', '/erklaerung-maengel-h4-mit-stand', 1, 0, 79616, strftime('%s','now'), strftime('%s','now'), 1, 0, 31, 27, 0),
(312, 1, 'Erklärung maengel h4 ohne Stand', '/erklaerung-maengel-h4-ohne-stand', 1, 0, 79872, strftime('%s','now'), strftime('%s','now'), 1, 0, 31, 27, 0),
(313, 1, 'Erklärung kontakt h2 mit Stand', '/erklaerung-kontakt-h2-mit-stand', 1, 0, 80128, strftime('%s','now'), strftime('%s','now'), 1, 0, 31, 27, 0),
(314, 1, 'Erklärung kontakt h2 ohne Stand', '/erklaerung-kontakt-h2-ohne-stand', 1, 0, 80384, strftime('%s','now'), strftime('%s','now'), 1, 0, 31, 27, 0),
(315, 1, 'Erklärung kontakt h3 mit Stand', '/erklaerung-kontakt-h3-mit-stand', 1, 0, 80640, strftime('%s','now'), strftime('%s','now'), 1, 0, 31, 27, 0),
(316, 1, 'Erklärung kontakt h3 ohne Stand', '/erklaerung-kontakt-h3-ohne-stand', 1, 0, 80896, strftime('%s','now'), strftime('%s','now'), 1, 0, 31, 27, 0),
(317, 1, 'Erklärung kontakt h4 mit Stand', '/erklaerung-kontakt-h4-mit-stand', 1, 0, 81152, strftime('%s','now'), strftime('%s','now'), 1, 0, 31, 27, 0),
(318, 1, 'Erklärung kontakt h4 ohne Stand', '/erklaerung-kontakt-h4-ohne-stand', 1, 0, 81408, strftime('%s','now'), strftime('%s','now'), 1, 0, 31, 27, 0);
INSERT OR REPLACE INTO tt_content (uid, pid, colPos, CType, sys_language_uid, tx_barrierepruefung_teil, tx_barrierepruefung_ueberschrift, tx_barrierepruefung_stand, tstamp, crdate)
VALUES
(3001, 301, 0, 'barrierepruefung_erklaerung', 0, 'komplett', 2, 1, strftime('%s','now'), strftime('%s','now')),
(3002, 302, 0, 'barrierepruefung_erklaerung', 0, 'komplett', 2, 0, strftime('%s','now'), strftime('%s','now')),
(3003, 303, 0, 'barrierepruefung_erklaerung', 0, 'komplett', 3, 1, strftime('%s','now'), strftime('%s','now')),
(3004, 304, 0, 'barrierepruefung_erklaerung', 0, 'komplett', 3, 0, strftime('%s','now'), strftime('%s','now')),
(3005, 305, 0, 'barrierepruefung_erklaerung', 0, 'komplett', 4, 1, strftime('%s','now'), strftime('%s','now')),
(3006, 306, 0, 'barrierepruefung_erklaerung', 0, 'komplett', 4, 0, strftime('%s','now'), strftime('%s','now')),
(3007, 307, 0, 'barrierepruefung_erklaerung', 0, 'maengel', 2, 1, strftime('%s','now'), strftime('%s','now')),
(3008, 308, 0, 'barrierepruefung_erklaerung', 0, 'maengel', 2, 0, strftime('%s','now'), strftime('%s','now')),
(3009, 309, 0, 'barrierepruefung_erklaerung', 0, 'maengel', 3, 1, strftime('%s','now'), strftime('%s','now')),
(3010, 310, 0, 'barrierepruefung_erklaerung', 0, 'maengel', 3, 0, strftime('%s','now'), strftime('%s','now')),
(3011, 311, 0, 'barrierepruefung_erklaerung', 0, 'maengel', 4, 1, strftime('%s','now'), strftime('%s','now')),
(3012, 312, 0, 'barrierepruefung_erklaerung', 0, 'maengel', 4, 0, strftime('%s','now'), strftime('%s','now')),
(3013, 313, 0, 'barrierepruefung_erklaerung', 0, 'kontakt', 2, 1, strftime('%s','now'), strftime('%s','now')),
(3014, 314, 0, 'barrierepruefung_erklaerung', 0, 'kontakt', 2, 0, strftime('%s','now'), strftime('%s','now')),
(3015, 315, 0, 'barrierepruefung_erklaerung', 0, 'kontakt', 3, 1, strftime('%s','now'), strftime('%s','now')),
(3016, 316, 0, 'barrierepruefung_erklaerung', 0, 'kontakt', 3, 0, strftime('%s','now'), strftime('%s','now')),
(3017, 317, 0, 'barrierepruefung_erklaerung', 0, 'kontakt', 4, 1, strftime('%s','now'), strftime('%s','now')),
(3018, 318, 0, 'barrierepruefung_erklaerung', 0, 'kontakt', 4, 0, strftime('%s','now'), strftime('%s','now'));
