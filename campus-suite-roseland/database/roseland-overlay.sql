SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

UPDATE users
SET email = 'admin@roselandschool.org',
    display_name = 'ROSELAND SCHOOL Admin',
    password_hash = '$2y$10$AgbSrdG6O5LtF9MrTB3BmO45Za3HZMMv8J3X5ao7kAu4Y297Oo6qW',
    role = 'admin'
WHERE id = 1;

UPDATE erp_institutions
SET name = 'ROSELAND SCHOOL',
    code = 'ROSELAND',
    type = 'school',
    email = 'roselandschool107@gmail.com',
    phone = '9822070349',
    address = '152/1, Handinimgaon, Tq. Newasa, Dist. Ahmednagar'
WHERE id = 1;

DELETE FROM carousel_slides;
INSERT INTO carousel_slides (id, sort_order, title, excerpt, image_url, body_html, link_post_id, link_url) VALUES
(1, 0, 'Admissions open at ROSELAND SCHOOL', 'Pre-primary, primary, secondary and junior college admissions for Nursery to Class XII.', '/media/roseland/front-web.jpg', 'Parents can apply online and the school office can manage applications from Campus Suite ERP.', NULL, '/admission'),
(2, 1, 'A caring campus for young learners', 'Bright classrooms, library, computer room and activity-based learning for every child.', '/media/roseland/building-web.jpg', 'All homepage carousel slides are editable from the Campus Suite CMS admin panel.', NULL, '/p/about'),
(3, 2, 'Learn, play and grow with confidence', 'ROSELAND SCHOOL focuses on strong basics, discipline, creativity and board preparation.', '/media/roseland/class-room-web.jpg', 'Publish notices, events, gallery photos and pages from the same admin application.', NULL, '/p/gallery');

DELETE FROM gallery_items;
INSERT INTO gallery_items (id, sort_order, media_type, url, caption) VALUES
(1, 0, 'image', '/media/roseland/front-web.jpg', 'ROSELAND SCHOOL front view'),
(2, 1, 'image', '/media/roseland/building-web.jpg', 'School building'),
(3, 2, 'image', '/media/roseland/class-room-web.jpg', 'Classroom learning'),
(4, 3, 'image', '/media/roseland/computer-room-web.jpg', 'Computer room'),
(5, 4, 'image', '/media/roseland/library-web.jpg', 'Library'),
(6, 5, 'image', '/media/roseland/laboratory-web.jpg', 'Laboratory'),
(7, 6, 'image', '/media/roseland/reception-web.jpg', 'Reception and parent support');

UPDATE site_chrome
SET header_json = '{"minHeightPx":118,"maxHeightPx":null,"leftLogos":[{"url":"/media/roseland/logo-web.jpg","alt":"ROSELAND SCHOOL","maxHeightPx":76}],"rightLogos":[],"center":{"mode":"text","imageUrl":null,"imageMaxHeightPx":112,"lines":[{"text":"ROSELAND SCHOOL","fontSizePx":42,"fontWeight":"900","fontStyle":"normal","fontFamily":"serif","color":"#0f172a"},{"text":"Pre-primary, Primary, Secondary and Junior College","fontSizePx":17,"fontWeight":"700","fontStyle":"normal","fontFamily":"sans","color":"#075985"},{"text":"152/1, Handinimgaon, Tq. Newasa, Dist. Ahmednagar | 9822070349 | roselandschool107@gmail.com","fontSizePx":14,"fontWeight":"600","fontStyle":"normal","fontFamily":"sans","color":"#475569"}]}}',
    footer_json = '{"mode":"text","imageUrl":null,"imageMaxHeightPx":56,"lines":[{"text":"ROSELAND SCHOOL","fontSizePx":24,"fontWeight":"900","fontStyle":"normal","fontFamily":"serif","color":"#ffffff"},{"text":"Learning with Joy | Nursery to Class XII","fontSizePx":14,"fontWeight":"500","fontStyle":"normal","fontFamily":"sans","color":"#cbd5e1"},{"text":"152/1, Handinimgaon, Tq. Newasa, Dist. Ahmednagar | Phone: 9822070349 | Email: roselandschool107@gmail.com","fontSizePx":14,"fontWeight":"600","fontStyle":"normal","fontFamily":"sans","color":"#bae6fd"}]}'
WHERE id = 1;

UPDATE site_home
SET content_json = '{"hero":{"title":"ROSELAND SCHOOL","subtitle":"A cheerful pre-primary, primary, secondary and junior college campus for strong basics, confident children and bright futures.","tagline":"Handinimgaon, Newasa, Ahmednagar | Nursery to Class XII","image_url":"/media/roseland/front-web.jpg","primary_cta_label":"Apply for Admission","primary_cta_href":"/admission","secondary_cta_label":"View Gallery","secondary_cta_href":"/p/gallery","stats":[{"label":"Classes","value":"Nursery-XII"},{"label":"Campus","value":"Safe & Caring"},{"label":"Learning","value":"Activity Based"},{"label":"Contact","value":"9822070349"}]},"sections":[{"id":"about-roseland","heading":"About ROSELAND SCHOOL","subheading":"A caring school path from early years to board preparation","body_html":"<p>ROSELAND SCHOOL at Handinimgaon supports children from pre-primary foundations through primary, secondary and junior college learning. The school focuses on reading, writing, mathematics, science, discipline, values, activities and parent communication.</p>","variant":"default"},{"id":"classes-offered","heading":"Classes offered","subheading":"Pre-primary to Class XII","body_html":"<p><strong>Pre-primary:</strong> Nursery, LKG and UKG with playful learning, stories, rhymes and early concepts.</p><p><strong>Primary:</strong> Class I to V with strong language, maths and environmental studies.</p><p><strong>Secondary and Junior College:</strong> Class VI to XII with structured academics, practice and exam readiness.</p>","variant":"muted"},{"id":"campus-facilities","heading":"Campus facilities","subheading":"Classrooms, library, computer room and laboratory","body_html":"<p>The campus includes learning spaces for classroom teaching, library reading, computer education, laboratory exposure and parent-friendly reception support.</p>","variant":"accent"},{"id":"admin-managed","heading":"Managed from Campus Suite CMS","subheading":"Website and ERP in one admin application","body_html":"<p>School staff can update home content, pages, navigation, carousel slides, gallery photos, notices, events and admission records from the Campus Suite admin panel.</p>","variant":"accent"}],"show_latest_posts":true,"latest_posts_heading":"Latest from ROSELAND SCHOOL","latest_posts_intro":"News, notices, events and updates managed by the school admin team."}'
WHERE id = 1;

DELETE FROM nav_items;
INSERT INTO nav_items (id, parent_id, sort_order, label, page_id, post_id, url)
SELECT 1, NULL, 0, 'About', id, NULL, NULL FROM site_pages WHERE slug = 'about' LIMIT 1;
INSERT INTO nav_items (id, parent_id, sort_order, label, page_id, post_id, url)
SELECT 2, NULL, 1, 'Gallery', id, NULL, NULL FROM site_pages WHERE slug = 'gallery' LIMIT 1;
INSERT INTO nav_items (id, parent_id, sort_order, label, page_id, post_id, url)
SELECT 3, NULL, 2, 'Admissions', NULL, NULL, '/admission';
INSERT INTO nav_items (id, parent_id, sort_order, label, page_id, post_id, url)
SELECT 4, NULL, 3, 'News & Events', id, NULL, NULL FROM site_pages WHERE slug = 'news-events' LIMIT 1;
INSERT INTO nav_items (id, parent_id, sort_order, label, page_id, post_id, url)
SELECT 5, NULL, 4, 'Contact', id, NULL, NULL FROM site_pages WHERE slug = 'contact' LIMIT 1;

UPDATE site_pages SET title = 'About ROSELAND SCHOOL', content_html = '<div class="prose-blog"><p><strong>ROSELAND SCHOOL</strong> is located at 152/1, Handinimgaon, Tq. Newasa, Dist. Ahmednagar. The school supports pre-primary, primary, secondary and junior college learning with a caring approach for children and parents.</p><p>Use the Campus Suite admin panel to update this page, add more school information and publish notices whenever required.</p></div>' WHERE slug = 'about';
UPDATE site_pages SET title = 'Campus Gallery', content_html = '<div class="prose-blog"><p>Photos of ROSELAND SCHOOL campus, classrooms, library, computer room and learning spaces. Gallery items are managed from the CMS admin panel.</p></div>' WHERE slug = 'gallery';
UPDATE site_pages SET title = 'Contact ROSELAND SCHOOL', content_html = '<div class="prose-blog"><p><strong>Address:</strong> 152/1, Handinimgaon, Tq. Newasa, Dist. Ahmednagar</p><p><strong>Phone:</strong> 9822070349</p><p><strong>Email:</strong> roselandschool107@gmail.com</p></div>' WHERE slug = 'contact';
UPDATE site_pages SET title = 'News & Events', content_html = '<div class="prose-blog"><p>Latest notices, school events and announcements from ROSELAND SCHOOL.</p></div>' WHERE slug = 'news-events';

DELETE FROM post_categories;
DELETE FROM posts;
INSERT INTO posts (id, user_id, title, slug, excerpt, content_html, cover_image_url, status, published_at) VALUES
(1, 1, 'Welcome to ROSELAND SCHOOL', 'welcome-to-roseland-school', 'Admissions and school updates for parents and students.', '<p>Welcome to ROSELAND SCHOOL. This website is connected with Campus Suite so the school admin can manage content, notices, gallery and admissions from one dashboard.</p>', '/media/roseland/building-web.jpg', 'published', NOW()),
(2, 1, 'Admissions open for Nursery to Class XII', 'admissions-open-nursery-to-class-xii', 'Submit admission enquiries online and contact the school office for guidance.', '<p>Admissions are open for pre-primary, primary, secondary and junior college classes. Parents may apply online or contact the school office at 9822070349.</p>', '/media/roseland/front-web.jpg', 'published', NOW());
INSERT INTO post_categories (post_id, category_id) VALUES (1, 1), (1, 2), (2, 2);

DELETE FROM events;
INSERT INTO events (id, slug, title, excerpt, content_html, sort_order, status, published_at) VALUES
(1, 'admission-counselling', 'Admission counselling and school visit', 'Parents can contact the school office for admission guidance and campus visit timing.', '<p>ROSELAND SCHOOL welcomes parents to visit the campus and discuss admission requirements for Nursery to Class XII.</p>', 0, 'published', NOW());

DELETE FROM erp_fee_invoices;
DELETE FROM erp_fee_plans;
DELETE FROM erp_sections;
DELETE FROM erp_classes;
INSERT INTO erp_classes (id, institution_id, name, level_order) VALUES
(1, 1, 'Nursery', 1),(2, 1, 'LKG', 2),(3, 1, 'UKG', 3),(4, 1, 'Class I', 4),(5, 1, 'Class II', 5),(6, 1, 'Class III', 6),(7, 1, 'Class IV', 7),(8, 1, 'Class V', 8),(9, 1, 'Class VI', 9),(10, 1, 'Class VII', 10),(11, 1, 'Class VIII', 11),(12, 1, 'Class IX', 12),(13, 1, 'Class X', 13),(14, 1, 'Class XI', 14),(15, 1, 'Class XII', 15);
INSERT INTO erp_sections (id, class_id, name, capacity) VALUES
(1,1,'A',40),(2,2,'A',40),(3,3,'A',40),(4,4,'A',45),(5,5,'A',45),(6,6,'A',45),(7,7,'A',45),(8,8,'A',45),(9,9,'A',50),(10,10,'A',50),(11,11,'A',50),(12,12,'A',50),(13,13,'A',50),(14,14,'A',60),(15,15,'A',60);

SET FOREIGN_KEY_CHECKS = 1;




