-- RxSource company contact preferences data update (MySQL / MariaDB).
-- Reuses existing names and IDs. Requires the existing cms_preferences table.
-- First installation replaces template contact values with the current footer details.
-- Subsequent runs preserve edits made through Company Details.
-- Take a database backup before applying to another environment.

START TRANSACTION;

SET @rx_company_initial = (SELECT COUNT(*) = 0 FROM cms_preferences WHERE name = 'prefUSAAddress');

INSERT INTO cms_preferences
  (name, label, value, migrule, notes, prefCat, field, class, userlevel, sort, comment, placeholder, required, max, min, step, tooltip, showoncms, showonweb, allowedit, archived)
VALUES
  ('prefCompanyName', 'Company — Company name', 'RxSource', 'copy', '', 1, 1, 'medium', 30, 1010, '', '', 'No', 2048, 0, 1, '', 'Yes', 'Yes', 'Yes', 0),
  ('prefEmail', 'Company — Contact email', 'solutions@rxsource.com', 'copy', '', 1, 1, 'medium', 30, 1020, '', '', 'No', 2048, 0, 1, '', 'Yes', 'Yes', 'Yes', 0),
  ('prefWebsite', 'Company — Website', 'https://www.rxsource.com/', 'copy', '', 1, 1, 'medium', 30, 1030, '', '', 'No', 2048, 0, 1, '', 'Yes', 'Yes', 'Yes', 0),
  ('prefCanadaLabel', 'Canada — Office heading', 'CANADA', 'copy', '', 1, 1, 'medium', 30, 1040, '', '', 'No', 2048, 0, 1, '', 'Yes', 'Yes', 'Yes', 0),
  ('prefAddress1', 'Canada — Address line 1', '74-556 Edward Ave', 'copy', '', 1, 1, 'medium', 30, 1050, '', '', 'No', 2048, 0, 1, '', 'Yes', 'Yes', 'Yes', 0),
  ('prefAddress2', 'Canada — Address line 2', '', 'copy', '', 1, 1, 'medium', 30, 1060, '', '', 'No', 2048, 0, 1, '', 'Yes', 'Yes', 'Yes', 0),
  ('prefTown', 'Canada — Town / city', 'Richmond Hill', 'copy', '', 1, 1, 'medium', 30, 1070, '', '', 'No', 2048, 0, 1, '', 'Yes', 'Yes', 'Yes', 0),
  ('prefCounty', 'Canada — Province / state', '', 'copy', '', 1, 1, 'medium', 30, 1080, '', '', 'No', 2048, 0, 1, '', 'Yes', 'Yes', 'Yes', 0),
  ('prefCountry', 'Canada — Country', 'Canada', 'copy', '', 1, 1, 'medium', 30, 1090, '', '', 'No', 2048, 0, 1, '', 'Yes', 'Yes', 'Yes', 0),
  ('prefPostcode', 'Canada — Postal code', 'L4C 9Y5', 'copy', '', 1, 1, 'medium', 30, 1100, '', '', 'No', 2048, 0, 1, '', 'Yes', 'Yes', 'Yes', 0),
  ('prefTel1', 'Canada — Telephone (include country code)', '+1 905 883 4333', 'copy', '', 1, 1, 'medium', 30, 1110, '', '', 'No', 2048, 0, 1, '', 'Yes', 'Yes', 'Yes', 0),
  ('prefUSALabel', 'USA — Office heading', 'USA', 'copy', '', 1, 1, 'medium', 30, 1120, '', '', 'No', 2048, 0, 1, '', 'Yes', 'Yes', 'Yes', 0),
  ('prefUSAAddress', 'USA — Address (one line per address line)', 'Unit 300
1240 Forest Parkway
West Deptford
New Jersey
USA
08066', 'copy', '', 1, 1, 'medium', 30, 1130, '', '', 'No', 2048, 0, 1, '', 'Yes', 'Yes', 'Yes', 0),
  ('prefTel2', 'USA — Telephone (include country code)', '+1 905 883 4333', 'copy', '', 1, 1, 'medium', 30, 1140, '', '', 'No', 2048, 0, 1, '', 'Yes', 'Yes', 'Yes', 0),
  ('prefEuropeLabel', 'Europe — Office heading', 'EUROPE', 'copy', '', 1, 1, 'medium', 30, 1150, '', '', 'No', 2048, 0, 1, '', 'Yes', 'Yes', 'Yes', 0),
  ('prefEuropeAddress', 'Europe — Address (one line per address line)', 'Unit 506
Northwest Business Park, Ballycoolin
Dublin 15
Ireland', 'copy', '', 1, 1, 'medium', 30, 1160, '', '', 'No', 2048, 0, 1, '', 'Yes', 'Yes', 'Yes', 0),
  ('prefTel3', 'Europe — Telephone (include country code)', '+353 (1) 963-1100', 'copy', '', 1, 1, 'medium', 30, 1170, '', '', 'No', 2048, 0, 1, '', 'Yes', 'Yes', 'Yes', 0),
  ('prefLinkedIn', 'Social links — LinkedIn', 'https://www.linkedin.com/company/rxsource', 'copy', '', 3, 1, 'medium', 30, 1180, '', '', 'No', 2048, 0, 1, '', 'Yes', 'Yes', 'Yes', 0),
  ('prefTwitter', 'Social links — X / Twitter', 'https://twitter.com/rxsource', 'copy', '', 3, 1, 'medium', 30, 1190, '', '', 'No', 2048, 0, 1, '', 'Yes', 'Yes', 'Yes', 0),
  ('prefInstagram', 'Social links — Instagram', 'https://www.instagram.com/rxsource', 'copy', '', 3, 1, 'medium', 30, 1200, '', '', 'No', 2048, 0, 1, '', 'Yes', 'Yes', 'Yes', 0),
  ('prefYouTube', 'Social links — YouTube', 'https://www.youtube.com/@RxSource', 'copy', '', 3, 1, 'medium', 30, 1210, '', '', 'No', 2048, 0, 1, '', 'Yes', 'Yes', 'Yes', 0)
ON DUPLICATE KEY UPDATE
  value = IF(@rx_company_initial, VALUES(value), value),
  label = IF(@rx_company_initial, VALUES(label), label),
  showoncms = IF(@rx_company_initial, 'Yes', showoncms),
  showonweb = IF(@rx_company_initial, 'Yes', showonweb),
  archived = IF(@rx_company_initial, 0, archived);

COMMIT;
