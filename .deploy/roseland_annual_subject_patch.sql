SET @institution_id := (SELECT id FROM erp_institutions ORDER BY id LIMIT 1);
UPDATE erp_course_year_subject_groups
SET selection_type = 'auto_all', min_select = 3, max_select = 3
WHERE institution_id = @institution_id
  AND course_name = 'Higher Secondary'
  AND group_key = 'compulsory';
UPDATE erp_course_year_group_subjects gs
JOIN erp_course_year_subject_groups g ON g.id = gs.group_id
JOIN erp_subjects s ON s.id = gs.subject_id
SET gs.semester_no = 1, gs.is_default = 1
WHERE g.institution_id = @institution_id
  AND g.course_name = 'Higher Secondary'
  AND g.group_key = 'compulsory'
  AND s.code IN ('ENG','EVS','PE');
SELECT year_name, group_name, selection_type, min_select, max_select FROM erp_course_year_subject_groups WHERE course_name='Higher Secondary' ORDER BY year_name, sort_order;
