SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DELETE FROM erp_section_subjects;
DELETE FROM erp_section_subject_limits;
DELETE FROM erp_course_subject_plans;
DELETE FROM erp_subjects;
DELETE FROM erp_fee_plans;
DELETE FROM erp_staff;

INSERT INTO erp_subjects (id, institution_id, code, name, subject_type) VALUES
(1, 1, 'ENG', 'English', 'Core'),
(2, 1, 'MAR', 'Marathi', 'Core'),
(3, 1, 'HIN', 'Hindi', 'Core'),
(4, 1, 'MAT', 'Mathematics', 'Core'),
(5, 1, 'EVS', 'Environmental Studies', 'Core'),
(6, 1, 'SCI', 'Science', 'Core'),
(7, 1, 'SST', 'Social Studies', 'Core'),
(8, 1, 'ICT', 'Computer Studies', 'Skill'),
(9, 1, 'ART', 'Art and Craft', 'Activity'),
(10, 1, 'PE', 'Physical Education', 'Activity'),
(11, 1, 'PHY', 'Physics', 'Higher Secondary'),
(12, 1, 'CHE', 'Chemistry', 'Higher Secondary'),
(13, 1, 'BIO', 'Biology', 'Higher Secondary'),
(14, 1, 'ACCT', 'Accountancy', 'Higher Secondary'),
(15, 1, 'ECO', 'Economics', 'Higher Secondary');

INSERT INTO erp_section_subjects (section_id, subject_id, semester_no, is_mandatory)
SELECT sec.id, subj.id, 1, 1
FROM erp_sections sec
JOIN erp_classes cls ON cls.id = sec.class_id
JOIN erp_subjects subj ON subj.code IN ('ENG','MAR','MAT','EVS','ART','PE')
WHERE cls.name IN ('Nursery','LKG','UKG','Class I','Class II','Class III','Class IV','Class V');

INSERT INTO erp_section_subjects (section_id, subject_id, semester_no, is_mandatory)
SELECT sec.id, subj.id, 1, 1
FROM erp_sections sec
JOIN erp_classes cls ON cls.id = sec.class_id
JOIN erp_subjects subj ON subj.code IN ('ENG','MAR','HIN','MAT','SCI','SST','ICT','PE')
WHERE cls.name IN ('Class VI','Class VII','Class VIII','Class IX','Class X');

INSERT INTO erp_section_subjects (section_id, subject_id, semester_no, is_mandatory)
SELECT sec.id, subj.id, 1, CASE WHEN subj.code IN ('ENG','PE') THEN 1 ELSE 0 END
FROM erp_sections sec
JOIN erp_classes cls ON cls.id = sec.class_id
JOIN erp_subjects subj ON subj.code IN ('ENG','MAT','PHY','CHE','BIO','ICT','ACCT','ECO','PE')
WHERE cls.name IN ('Class XI','Class XII');

INSERT INTO erp_section_subject_limits (section_id, semester_no, max_subjects)
SELECT id, 1, CASE WHEN class_id IN (14,15) THEN 6 ELSE 8 END FROM erp_sections;

INSERT INTO erp_fee_plans (institution_id, academic_year_id, class_id, name, amount, due_on) VALUES
(1, 1, 1, 'Pre-primary Admission Fee', 5000.00, '2026-06-15'),
(1, 1, 2, 'LKG Annual Fee', 8500.00, '2026-06-15'),
(1, 1, 3, 'UKG Annual Fee', 9000.00, '2026-06-15'),
(1, 1, 4, 'Primary Annual Fee', 12000.00, '2026-06-15'),
(1, 1, 8, 'Upper Primary Annual Fee', 14500.00, '2026-06-15'),
(1, 1, 13, 'Secondary Board Class Fee', 18000.00, '2026-06-15'),
(1, 1, 14, 'Higher Secondary Class XI Fee', 22000.00, '2026-06-15'),
(1, 1, 15, 'Higher Secondary Class XII Fee', 24000.00, '2026-06-15'),
(1, 1, NULL, 'Transport Monthly Fee', 1200.00, '2026-06-05');

INSERT INTO erp_staff (institution_id, employee_no, first_name, last_name, email, phone, role, department, status, joined_on) VALUES
(1, 'RSL-T-001', 'Anita', 'Patil', 'primary@roselandschool.org', '9822070349', 'Teacher', 'Primary', 'active', '2026-06-01'),
(1, 'RSL-T-002', 'Ramesh', 'Shinde', 'secondary@roselandschool.org', '9822070349', 'Teacher', 'Secondary', 'active', '2026-06-01'),
(1, 'RSL-T-003', 'Sneha', 'Kale', 'science@roselandschool.org', '9822070349', 'Teacher', 'Higher Secondary Science', 'active', '2026-06-01'),
(1, 'RSL-A-001', 'Office', 'Admin', 'roselandschool107@gmail.com', '9822070349', 'Administrator', 'Office', 'active', '2026-06-01');

DELETE FROM posts;
INSERT INTO posts (id, user_id, title, slug, excerpt, content_html, cover_image_url, status, published_at) VALUES
(1, 1, 'Admissions open for Nursery to Class XII', 'admissions-open-nursery-to-class-xii', 'ROSELAND SCHOOL admissions are open for pre-primary, primary, secondary and higher secondary classes.', '<p>Parents can submit admission enquiries online for Nursery, LKG, UKG, Class I to X and higher secondary Class XI-XII.</p>', '/media/roseland/front-web.jpg', 'published', NOW()),
(2, 1, 'Primary and secondary academic focus', 'primary-secondary-academic-focus', 'Strong basics in languages, mathematics, science, social studies and computer learning.', '<p>ROSELAND SCHOOL supports primary and secondary learners with structured academics, activities, discipline and parent communication.</p>', '/media/roseland/class-room-web.jpg', 'published', NOW()),
(3, 1, 'Higher secondary subject guidance', 'higher-secondary-subject-guidance', 'Class XI and XII students receive guidance for science, commerce and academic planning.', '<p>Higher secondary students can receive guidance for Physics, Chemistry, Biology, Mathematics, Accountancy, Economics and computer studies.</p>', '/media/roseland/laboratory-web.jpg', 'published', NOW());

DELETE FROM post_categories;
INSERT INTO post_categories (post_id, category_id) VALUES (1, 2), (2, 1), (3, 2);

DELETE FROM events;
INSERT INTO events (id, slug, title, excerpt, content_html, sort_order, status, published_at) VALUES
(1, 'school-admission-counselling', 'School admission counselling', 'Meet the office team for Nursery to Class XII admission guidance.', '<p>Parents can contact ROSELAND SCHOOL for admission guidance, campus visit timing and required documents.</p>', 0, 'published', NOW()),
(2, 'primary-orientation', 'Primary orientation week', 'Orientation activities for new primary students and parents.', '<p>Primary orientation introduces children and parents to classroom routines, activities and learning expectations.</p>', 1, 'published', NOW()),
(3, 'secondary-exam-readiness', 'Secondary exam readiness session', 'Guidance for Class IX-X students on study habits and preparation.', '<p>Secondary students receive guidance for regular practice, revision planning and board exam readiness.</p>', 2, 'published', NOW());

UPDATE erp_email_settings SET from_name = 'ROSELAND SCHOOL Admissions' WHERE id = 1;

SET FOREIGN_KEY_CHECKS = 1;
