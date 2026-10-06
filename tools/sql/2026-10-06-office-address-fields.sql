-- Upgrade existing USA/Europe multiline addresses to separate preference fields.
-- Apply this BEFORE 2026-10-06-company-details.sql when upgrading the old layout.
-- Existing separate fields and unrelated contact/social preferences are preserved.
-- Legacy records are retained as archived references, not deleted.
START TRANSACTION;

INSERT INTO cms_preferences
  (name,label,value,migrule,notes,prefCat,field,class,userlevel,sort,comment,placeholder,required,max,min,step,tooltip,showoncms,showonweb,allowedit,archived)
SELECT 'prefUSAAddress1', 'USA — Address line 1', IF(1 + LENGTH(REPLACE(REPLACE(value, CONCAT(CHAR(13), CHAR(10)), CHAR(10)), CHAR(13), CHAR(10))) - LENGTH(REPLACE(REPLACE(REPLACE(value, CONCAT(CHAR(13), CHAR(10)), CHAR(10)), CHAR(13), CHAR(10)), CHAR(10), '')) >= 1, TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(REPLACE(REPLACE(value, CONCAT(CHAR(13), CHAR(10)), CHAR(10)), CHAR(13), CHAR(10)), CHAR(10), 1), CHAR(10), -1)), ''),
  'copy','',1,1,'medium',30,1130,'','','No',2048,0,1,'','Yes','Yes','Yes',0
FROM cms_preferences AS legacy WHERE legacy.name = 'prefUSAAddress'
ON DUPLICATE KEY UPDATE id = cms_preferences.id;

INSERT INTO cms_preferences
  (name,label,value,migrule,notes,prefCat,field,class,userlevel,sort,comment,placeholder,required,max,min,step,tooltip,showoncms,showonweb,allowedit,archived)
SELECT 'prefUSAAddress2', 'USA — Address line 2', IF(1 + LENGTH(REPLACE(REPLACE(value, CONCAT(CHAR(13), CHAR(10)), CHAR(10)), CHAR(13), CHAR(10))) - LENGTH(REPLACE(REPLACE(REPLACE(value, CONCAT(CHAR(13), CHAR(10)), CHAR(10)), CHAR(13), CHAR(10)), CHAR(10), '')) >= 2, TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(REPLACE(REPLACE(value, CONCAT(CHAR(13), CHAR(10)), CHAR(10)), CHAR(13), CHAR(10)), CHAR(10), 2), CHAR(10), -1)), ''),
  'copy','',1,1,'medium',30,1140,'','','No',2048,0,1,'','Yes','Yes','Yes',0
FROM cms_preferences AS legacy WHERE legacy.name = 'prefUSAAddress'
ON DUPLICATE KEY UPDATE id = cms_preferences.id;

INSERT INTO cms_preferences
  (name,label,value,migrule,notes,prefCat,field,class,userlevel,sort,comment,placeholder,required,max,min,step,tooltip,showoncms,showonweb,allowedit,archived)
SELECT 'prefUSATown', 'USA — Town / city', IF(1 + LENGTH(REPLACE(REPLACE(value, CONCAT(CHAR(13), CHAR(10)), CHAR(10)), CHAR(13), CHAR(10))) - LENGTH(REPLACE(REPLACE(REPLACE(value, CONCAT(CHAR(13), CHAR(10)), CHAR(10)), CHAR(13), CHAR(10)), CHAR(10), '')) >= 3, TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(REPLACE(REPLACE(value, CONCAT(CHAR(13), CHAR(10)), CHAR(10)), CHAR(13), CHAR(10)), CHAR(10), 3), CHAR(10), -1)), ''),
  'copy','',1,1,'medium',30,1150,'','','No',2048,0,1,'','Yes','Yes','Yes',0
FROM cms_preferences AS legacy WHERE legacy.name = 'prefUSAAddress'
ON DUPLICATE KEY UPDATE id = cms_preferences.id;

INSERT INTO cms_preferences
  (name,label,value,migrule,notes,prefCat,field,class,userlevel,sort,comment,placeholder,required,max,min,step,tooltip,showoncms,showonweb,allowedit,archived)
SELECT 'prefUSACounty', 'USA — Province / state', IF(1 + LENGTH(REPLACE(REPLACE(value, CONCAT(CHAR(13), CHAR(10)), CHAR(10)), CHAR(13), CHAR(10))) - LENGTH(REPLACE(REPLACE(REPLACE(value, CONCAT(CHAR(13), CHAR(10)), CHAR(10)), CHAR(13), CHAR(10)), CHAR(10), '')) >= 4, TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(REPLACE(REPLACE(value, CONCAT(CHAR(13), CHAR(10)), CHAR(10)), CHAR(13), CHAR(10)), CHAR(10), 4), CHAR(10), -1)), ''),
  'copy','',1,1,'medium',30,1160,'','','No',2048,0,1,'','Yes','Yes','Yes',0
FROM cms_preferences AS legacy WHERE legacy.name = 'prefUSAAddress'
ON DUPLICATE KEY UPDATE id = cms_preferences.id;

INSERT INTO cms_preferences
  (name,label,value,migrule,notes,prefCat,field,class,userlevel,sort,comment,placeholder,required,max,min,step,tooltip,showoncms,showonweb,allowedit,archived)
SELECT 'prefUSACountry', 'USA — Country', IF(1 + LENGTH(REPLACE(REPLACE(value, CONCAT(CHAR(13), CHAR(10)), CHAR(10)), CHAR(13), CHAR(10))) - LENGTH(REPLACE(REPLACE(REPLACE(value, CONCAT(CHAR(13), CHAR(10)), CHAR(10)), CHAR(13), CHAR(10)), CHAR(10), '')) >= 5, TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(REPLACE(REPLACE(value, CONCAT(CHAR(13), CHAR(10)), CHAR(10)), CHAR(13), CHAR(10)), CHAR(10), 5), CHAR(10), -1)), ''),
  'copy','',1,1,'medium',30,1170,'','','No',2048,0,1,'','Yes','Yes','Yes',0
FROM cms_preferences AS legacy WHERE legacy.name = 'prefUSAAddress'
ON DUPLICATE KEY UPDATE id = cms_preferences.id;

INSERT INTO cms_preferences
  (name,label,value,migrule,notes,prefCat,field,class,userlevel,sort,comment,placeholder,required,max,min,step,tooltip,showoncms,showonweb,allowedit,archived)
SELECT 'prefUSAPostcode', 'USA — Postal code', IF(1 + LENGTH(REPLACE(REPLACE(value, CONCAT(CHAR(13), CHAR(10)), CHAR(10)), CHAR(13), CHAR(10))) - LENGTH(REPLACE(REPLACE(REPLACE(value, CONCAT(CHAR(13), CHAR(10)), CHAR(10)), CHAR(13), CHAR(10)), CHAR(10), '')) >= 6, TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(REPLACE(REPLACE(value, CONCAT(CHAR(13), CHAR(10)), CHAR(10)), CHAR(13), CHAR(10)), CHAR(10), 6), CHAR(10), -1)), ''),
  'copy','',1,1,'medium',30,1180,'','','No',2048,0,1,'','Yes','Yes','Yes',0
FROM cms_preferences AS legacy WHERE legacy.name = 'prefUSAAddress'
ON DUPLICATE KEY UPDATE id = cms_preferences.id;

UPDATE cms_preferences SET archived=1, showoncms='No', showonweb='No' WHERE name='prefUSAAddress';

INSERT INTO cms_preferences
  (name,label,value,migrule,notes,prefCat,field,class,userlevel,sort,comment,placeholder,required,max,min,step,tooltip,showoncms,showonweb,allowedit,archived)
SELECT 'prefEuropeAddress1', 'Europe — Address line 1', IF(1 + LENGTH(REPLACE(REPLACE(value, CONCAT(CHAR(13), CHAR(10)), CHAR(10)), CHAR(13), CHAR(10))) - LENGTH(REPLACE(REPLACE(REPLACE(value, CONCAT(CHAR(13), CHAR(10)), CHAR(10)), CHAR(13), CHAR(10)), CHAR(10), '')) >= 1, TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(REPLACE(REPLACE(value, CONCAT(CHAR(13), CHAR(10)), CHAR(10)), CHAR(13), CHAR(10)), CHAR(10), 1), CHAR(10), -1)), ''),
  'copy','',1,1,'medium',30,1210,'','','No',2048,0,1,'','Yes','Yes','Yes',0
FROM cms_preferences AS legacy WHERE legacy.name = 'prefEuropeAddress'
ON DUPLICATE KEY UPDATE id = cms_preferences.id;

INSERT INTO cms_preferences
  (name,label,value,migrule,notes,prefCat,field,class,userlevel,sort,comment,placeholder,required,max,min,step,tooltip,showoncms,showonweb,allowedit,archived)
SELECT 'prefEuropeAddress2', 'Europe — Address line 2', IF(1 + LENGTH(REPLACE(REPLACE(value, CONCAT(CHAR(13), CHAR(10)), CHAR(10)), CHAR(13), CHAR(10))) - LENGTH(REPLACE(REPLACE(REPLACE(value, CONCAT(CHAR(13), CHAR(10)), CHAR(10)), CHAR(13), CHAR(10)), CHAR(10), '')) >= 2, TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(REPLACE(REPLACE(value, CONCAT(CHAR(13), CHAR(10)), CHAR(10)), CHAR(13), CHAR(10)), CHAR(10), 2), CHAR(10), -1)), ''),
  'copy','',1,1,'medium',30,1220,'','','No',2048,0,1,'','Yes','Yes','Yes',0
FROM cms_preferences AS legacy WHERE legacy.name = 'prefEuropeAddress'
ON DUPLICATE KEY UPDATE id = cms_preferences.id;

INSERT INTO cms_preferences
  (name,label,value,migrule,notes,prefCat,field,class,userlevel,sort,comment,placeholder,required,max,min,step,tooltip,showoncms,showonweb,allowedit,archived)
SELECT 'prefEuropeTown', 'Europe — Town / city', IF(1 + LENGTH(REPLACE(REPLACE(value, CONCAT(CHAR(13), CHAR(10)), CHAR(10)), CHAR(13), CHAR(10))) - LENGTH(REPLACE(REPLACE(REPLACE(value, CONCAT(CHAR(13), CHAR(10)), CHAR(10)), CHAR(13), CHAR(10)), CHAR(10), '')) >= 3, TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(REPLACE(REPLACE(value, CONCAT(CHAR(13), CHAR(10)), CHAR(10)), CHAR(13), CHAR(10)), CHAR(10), 3), CHAR(10), -1)), ''),
  'copy','',1,1,'medium',30,1230,'','','No',2048,0,1,'','Yes','Yes','Yes',0
FROM cms_preferences AS legacy WHERE legacy.name = 'prefEuropeAddress'
ON DUPLICATE KEY UPDATE id = cms_preferences.id;

INSERT INTO cms_preferences
  (name,label,value,migrule,notes,prefCat,field,class,userlevel,sort,comment,placeholder,required,max,min,step,tooltip,showoncms,showonweb,allowedit,archived)
SELECT 'prefEuropeCounty', 'Europe — Province / state', '',
  'copy','',1,1,'medium',30,1240,'','','No',2048,0,1,'','Yes','Yes','Yes',0
FROM cms_preferences AS legacy WHERE legacy.name = 'prefEuropeAddress'
ON DUPLICATE KEY UPDATE id = cms_preferences.id;

INSERT INTO cms_preferences
  (name,label,value,migrule,notes,prefCat,field,class,userlevel,sort,comment,placeholder,required,max,min,step,tooltip,showoncms,showonweb,allowedit,archived)
SELECT 'prefEuropeCountry', 'Europe — Country', IF(1 + LENGTH(REPLACE(REPLACE(value, CONCAT(CHAR(13), CHAR(10)), CHAR(10)), CHAR(13), CHAR(10))) - LENGTH(REPLACE(REPLACE(REPLACE(value, CONCAT(CHAR(13), CHAR(10)), CHAR(10)), CHAR(13), CHAR(10)), CHAR(10), '')) >= 4, TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(REPLACE(REPLACE(value, CONCAT(CHAR(13), CHAR(10)), CHAR(10)), CHAR(13), CHAR(10)), CHAR(10), 4), CHAR(10), -1)), ''),
  'copy','',1,1,'medium',30,1250,'','','No',2048,0,1,'','Yes','Yes','Yes',0
FROM cms_preferences AS legacy WHERE legacy.name = 'prefEuropeAddress'
ON DUPLICATE KEY UPDATE id = cms_preferences.id;

INSERT INTO cms_preferences
  (name,label,value,migrule,notes,prefCat,field,class,userlevel,sort,comment,placeholder,required,max,min,step,tooltip,showoncms,showonweb,allowedit,archived)
SELECT 'prefEuropePostcode', 'Europe — Postal code', '',
  'copy','',1,1,'medium',30,1260,'','','No',2048,0,1,'','Yes','Yes','Yes',0
FROM cms_preferences AS legacy WHERE legacy.name = 'prefEuropeAddress'
ON DUPLICATE KEY UPDATE id = cms_preferences.id;

UPDATE cms_preferences SET archived=1, showoncms='No', showonweb='No' WHERE name='prefEuropeAddress';

COMMIT;
