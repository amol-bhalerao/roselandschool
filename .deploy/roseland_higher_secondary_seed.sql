SET @institution_id := (SELECT id FROM erp_institutions ORDER BY id LIMIT 1);

DELETE FROM erp_saved_records
WHERE module = 'Course master'
  AND (LOWER(name) IN ('b.a.','ba','b.sc.','bsc','bachelor of arts (b.a.)','bachelor of science (b.sc.)')
    OR LOWER(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.course'))) IN ('b.a.','ba','b.sc.','bsc','bachelor of arts (b.a.)','bachelor of science (b.sc.)'));
DELETE FROM erp_saved_records
WHERE module = 'Class course mapping'
  AND LOWER(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.course'))) IN ('b.a.','ba','b.sc.','bsc','bachelor of arts (b.a.)','bachelor of science (b.sc.)');

INSERT INTO erp_saved_records (id, user_id, module, name, code, status, payload_json)
VALUES
('rsl-course-pre-primary', NULL, 'Course master', 'Pre-Primary', 'PRE-PRIMARY', 'Active', JSON_OBJECT('course','Pre-Primary','shortName','PRE','notes','Nursery, LKG and UKG foundational learning')),
('rsl-course-primary', NULL, 'Course master', 'Primary', 'PRIMARY', 'Active', JSON_OBJECT('course','Primary','shortName','PRI','notes','Class I to V core school programme')),
('rsl-course-secondary', NULL, 'Course master', 'Secondary', 'SECONDARY', 'Active', JSON_OBJECT('course','Secondary','shortName','SEC','notes','Class VI to X board foundation programme')),
('rsl-course-higher-secondary', NULL, 'Course master', 'Higher Secondary', 'HIGHER-SECONDARY', 'Active', JSON_OBJECT('course','Higher Secondary','shortName','HSC','notes','Class XI and XII Arts and Science streams'))
ON DUPLICATE KEY UPDATE name = VALUES(name), code = VALUES(code), status = VALUES(status), payload_json = VALUES(payload_json);

INSERT INTO erp_classes (institution_id, name, level_order)
VALUES (@institution_id, 'Class XII Arts', 15), (@institution_id, 'Class XII Science', 15)
ON DUPLICATE KEY UPDATE level_order = VALUES(level_order);

SET @xi_arts := (SELECT id FROM erp_classes WHERE institution_id = @institution_id AND name = 'Class XI Arts' LIMIT 1);
SET @xi_science := (SELECT id FROM erp_classes WHERE institution_id = @institution_id AND name = 'Class XI Science' LIMIT 1);
SET @xii_arts := (SELECT id FROM erp_classes WHERE institution_id = @institution_id AND name = 'Class XII Arts' LIMIT 1);
SET @xii_science := (SELECT id FROM erp_classes WHERE institution_id = @institution_id AND name = 'Class XII Science' LIMIT 1);

DELETE FROM erp_saved_records WHERE module = 'Class course mapping' AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.classId')) IN (@xi_arts, @xi_science, @xii_arts, @xii_science);
INSERT INTO erp_saved_records (id, user_id, module, name, code, status, payload_json)
VALUES
(CONCAT('rsl-class-map-', @xi_arts), NULL, 'Class course mapping', 'Class XI Arts - Higher Secondary', 'CLASS-XI-ARTS-HIGHER-SECONDARY', 'Active', JSON_OBJECT('classId', @xi_arts, 'name', 'Class XI Arts', 'levelOrder', 14, 'course', 'Higher Secondary')),
(CONCAT('rsl-class-map-', @xi_science), NULL, 'Class course mapping', 'Class XI Science - Higher Secondary', 'CLASS-XI-SCIENCE-HIGHER-SECONDARY', 'Active', JSON_OBJECT('classId', @xi_science, 'name', 'Class XI Science', 'levelOrder', 14, 'course', 'Higher Secondary')),
(CONCAT('rsl-class-map-', @xii_arts), NULL, 'Class course mapping', 'Class XII Arts - Higher Secondary', 'CLASS-XII-ARTS-HIGHER-SECONDARY', 'Active', JSON_OBJECT('classId', @xii_arts, 'name', 'Class XII Arts', 'levelOrder', 15, 'course', 'Higher Secondary')),
(CONCAT('rsl-class-map-', @xii_science), NULL, 'Class course mapping', 'Class XII Science - Higher Secondary', 'CLASS-XII-SCIENCE-HIGHER-SECONDARY', 'Active', JSON_OBJECT('classId', @xii_science, 'name', 'Class XII Science', 'levelOrder', 15, 'course', 'Higher Secondary'));

INSERT INTO erp_sections (class_id, name, capacity)
VALUES (@xi_arts, 'A', 60), (@xi_science, 'A', 60), (@xii_arts, 'A', 60), (@xii_science, 'A', 60)
ON DUPLICATE KEY UPDATE capacity = VALUES(capacity);

INSERT INTO erp_subjects (institution_id, code, name, subject_type)
VALUES
(@institution_id, 'ENG', 'English', 'Core'),
(@institution_id, 'MAR', 'Marathi', 'Second Language'),
(@institution_id, 'HIN', 'Hindi', 'Second Language'),
(@institution_id, 'EVS', 'Environmental Studies', 'Core'),
(@institution_id, 'PE', 'Physical Education', 'Activity'),
(@institution_id, 'HIS', 'History', 'Arts'),
(@institution_id, 'POL', 'Political Science', 'Arts'),
(@institution_id, 'GEO', 'Geography', 'Arts'),
(@institution_id, 'SOC', 'Sociology', 'Arts'),
(@institution_id, 'ECO', 'Economics', 'Arts/Commerce'),
(@institution_id, 'PSY', 'Psychology', 'Arts'),
(@institution_id, 'PHY', 'Physics', 'Science'),
(@institution_id, 'CHE', 'Chemistry', 'Science'),
(@institution_id, 'BIO', 'Biology', 'Science'),
(@institution_id, 'MAT', 'Mathematics', 'Science'),
(@institution_id, 'CS', 'Computer Science', 'Science'),
(@institution_id, 'ICT', 'Information Technology', 'Skill')
ON DUPLICATE KEY UPDATE name = VALUES(name), subject_type = VALUES(subject_type);

DELETE FROM erp_subject_groups WHERE institution_id = @institution_id AND course_name IN ('Higher Secondary Arts', 'Higher Secondary Science');
INSERT INTO erp_subject_groups (institution_id, course_name, group_name, description)
VALUES
(@institution_id, 'Higher Secondary Arts', 'Languages', 'English with Marathi or Hindi'),
(@institution_id, 'Higher Secondary Arts', 'Arts optional subjects', 'History, Political Science, Geography, Sociology, Economics, Psychology'),
(@institution_id, 'Higher Secondary Science', 'Languages', 'English with Marathi or Hindi'),
(@institution_id, 'Higher Secondary Science', 'Science optional subjects', 'Physics, Chemistry, Biology, Mathematics, Computer Science, Information Technology');

INSERT IGNORE INTO erp_subject_group_subjects (group_id, subject_id)
SELECT g.id, s.id FROM erp_subject_groups g JOIN erp_subjects s ON s.institution_id = g.institution_id
WHERE g.institution_id = @institution_id AND g.course_name = 'Higher Secondary Arts' AND g.group_name = 'Languages' AND s.code IN ('ENG','MAR','HIN');
INSERT IGNORE INTO erp_subject_group_subjects (group_id, subject_id)
SELECT g.id, s.id FROM erp_subject_groups g JOIN erp_subjects s ON s.institution_id = g.institution_id
WHERE g.institution_id = @institution_id AND g.course_name = 'Higher Secondary Arts' AND g.group_name = 'Arts optional subjects' AND s.code IN ('HIS','POL','GEO','SOC','ECO','PSY');
INSERT IGNORE INTO erp_subject_group_subjects (group_id, subject_id)
SELECT g.id, s.id FROM erp_subject_groups g JOIN erp_subjects s ON s.institution_id = g.institution_id
WHERE g.institution_id = @institution_id AND g.course_name = 'Higher Secondary Science' AND g.group_name = 'Languages' AND s.code IN ('ENG','MAR','HIN');
INSERT IGNORE INTO erp_subject_group_subjects (group_id, subject_id)
SELECT g.id, s.id FROM erp_subject_groups g JOIN erp_subjects s ON s.institution_id = g.institution_id
WHERE g.institution_id = @institution_id AND g.course_name = 'Higher Secondary Science' AND g.group_name = 'Science optional subjects' AND s.code IN ('PHY','CHE','BIO','MAT','CS','ICT');

DELETE gs FROM erp_course_year_group_subjects gs JOIN erp_course_year_subject_groups g ON g.id = gs.group_id
WHERE g.institution_id = @institution_id AND g.course_name = 'Higher Secondary' AND g.year_name IN ('Class XI Arts','Class XI Science','Class XII Arts','Class XII Science');
DELETE FROM erp_course_year_subject_groups
WHERE institution_id = @institution_id AND course_name = 'Higher Secondary' AND year_name IN ('Class XI Arts','Class XI Science','Class XII Arts','Class XII Science');

INSERT INTO erp_course_year_subject_groups (institution_id, course_name, year_name, group_key, group_name, sort_order, selection_type, min_select, max_select, is_active, allow_student_choice)
VALUES
(@institution_id, 'Higher Secondary', 'Class XI Arts', 'compulsory', 'Compulsory Subjects', 1, 'fixed', 3, 3, 1, 0),
(@institution_id, 'Higher Secondary', 'Class XI Arts', 'second_language', 'Second Language', 2, 'select_one', 1, 1, 1, 1),
(@institution_id, 'Higher Secondary', 'Class XI Arts', 'arts_optional', 'Arts Optional Subjects', 3, 'select_many', 4, 4, 1, 1),
(@institution_id, 'Higher Secondary', 'Class XI Science', 'compulsory', 'Compulsory Subjects', 1, 'fixed', 3, 3, 1, 0),
(@institution_id, 'Higher Secondary', 'Class XI Science', 'second_language', 'Second Language', 2, 'select_one', 1, 1, 1, 1),
(@institution_id, 'Higher Secondary', 'Class XI Science', 'science_optional', 'Science Optional Subjects', 3, 'select_many', 4, 4, 1, 1),
(@institution_id, 'Higher Secondary', 'Class XII Arts', 'compulsory', 'Compulsory Subjects', 1, 'fixed', 3, 3, 1, 0),
(@institution_id, 'Higher Secondary', 'Class XII Arts', 'second_language', 'Second Language', 2, 'select_one', 1, 1, 1, 1),
(@institution_id, 'Higher Secondary', 'Class XII Arts', 'arts_optional', 'Arts Optional Subjects', 3, 'select_many', 4, 4, 1, 1),
(@institution_id, 'Higher Secondary', 'Class XII Science', 'compulsory', 'Compulsory Subjects', 1, 'fixed', 3, 3, 1, 0),
(@institution_id, 'Higher Secondary', 'Class XII Science', 'second_language', 'Second Language', 2, 'select_one', 1, 1, 1, 1),
(@institution_id, 'Higher Secondary', 'Class XII Science', 'science_optional', 'Science Optional Subjects', 3, 'select_many', 4, 4, 1, 1);

INSERT IGNORE INTO erp_course_year_group_subjects (group_id, semester_no, subject_id, subject_family_key, sort_order, is_default)
SELECT g.id, 1, s.id, LOWER(s.code), FIELD(s.code,'ENG','EVS','PE'), 1 FROM erp_course_year_subject_groups g JOIN erp_subjects s ON s.institution_id = g.institution_id
WHERE g.institution_id = @institution_id AND g.course_name = 'Higher Secondary' AND g.group_key = 'compulsory' AND s.code IN ('ENG','EVS','PE');
INSERT IGNORE INTO erp_course_year_group_subjects (group_id, semester_no, subject_id, subject_family_key, sort_order, is_default)
SELECT g.id, 1, s.id, LOWER(s.code), FIELD(s.code,'MAR','HIN'), 0 FROM erp_course_year_subject_groups g JOIN erp_subjects s ON s.institution_id = g.institution_id
WHERE g.institution_id = @institution_id AND g.course_name = 'Higher Secondary' AND g.group_key = 'second_language' AND s.code IN ('MAR','HIN');
INSERT IGNORE INTO erp_course_year_group_subjects (group_id, semester_no, subject_id, subject_family_key, sort_order, is_default)
SELECT g.id, 1, s.id, LOWER(s.code), FIELD(s.code,'HIS','POL','GEO','SOC','ECO','PSY'), 0 FROM erp_course_year_subject_groups g JOIN erp_subjects s ON s.institution_id = g.institution_id
WHERE g.institution_id = @institution_id AND g.course_name = 'Higher Secondary' AND g.group_key = 'arts_optional' AND s.code IN ('HIS','POL','GEO','SOC','ECO','PSY');
INSERT IGNORE INTO erp_course_year_group_subjects (group_id, semester_no, subject_id, subject_family_key, sort_order, is_default)
SELECT g.id, 1, s.id, LOWER(s.code), FIELD(s.code,'PHY','CHE','BIO','MAT','CS','ICT'), IF(s.code IN ('PHY','CHE'),1,0) FROM erp_course_year_subject_groups g JOIN erp_subjects s ON s.institution_id = g.institution_id
WHERE g.institution_id = @institution_id AND g.course_name = 'Higher Secondary' AND g.group_key = 'science_optional' AND s.code IN ('PHY','CHE','BIO','MAT','CS','ICT');

INSERT IGNORE INTO erp_section_subjects (section_id, subject_id, semester_no, is_mandatory)
SELECT sec.id, sub.id, 1, IF(sub.code IN ('ENG','EVS','PE'),1,0)
FROM erp_sections sec JOIN erp_classes cls ON cls.id = sec.class_id JOIN erp_subjects sub ON sub.institution_id = @institution_id
WHERE cls.id IN (@xi_arts, @xii_arts) AND sub.code IN ('ENG','MAR','HIN','EVS','PE','HIS','POL','GEO','SOC','ECO','PSY');
INSERT IGNORE INTO erp_section_subjects (section_id, subject_id, semester_no, is_mandatory)
SELECT sec.id, sub.id, 1, IF(sub.code IN ('ENG','EVS','PE','PHY','CHE'),1,0)
FROM erp_sections sec JOIN erp_classes cls ON cls.id = sec.class_id JOIN erp_subjects sub ON sub.institution_id = @institution_id
WHERE cls.id IN (@xi_science, @xii_science) AND sub.code IN ('ENG','MAR','HIN','EVS','PE','PHY','CHE','BIO','MAT','CS','ICT');

INSERT INTO erp_section_subject_limits (section_id, semester_no, max_subjects)
SELECT sec.id, 1, 8 FROM erp_sections sec WHERE sec.class_id IN (@xi_arts, @xi_science, @xii_arts, @xii_science)
ON DUPLICATE KEY UPDATE max_subjects = VALUES(max_subjects);

SELECT 'courses' AS item, COUNT(*) AS count FROM erp_saved_records WHERE module='Course master';
SELECT id,name,level_order FROM erp_classes WHERE name LIKE 'Class XI%' OR name LIKE 'Class XII%' ORDER BY level_order,name;
SELECT course_name,year_name,group_name,selection_type,min_select,max_select FROM erp_course_year_subject_groups WHERE course_name='Higher Secondary' ORDER BY year_name,sort_order;
