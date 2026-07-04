DROP TABLE IF EXISTS admissions;
DROP TABLE IF EXISTS cms_content;
DROP TABLE IF EXISTS school_settings;

CREATE TABLE school_settings (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  school_name VARCHAR(120) NOT NULL,
  address VARCHAR(255) NOT NULL,
  email VARCHAR(120) NOT NULL,
  phone VARCHAR(20) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO school_settings (school_name, address, email, phone)
VALUES (
  'ROSELAND SCHOOL',
  '152/1, Handinimgaon, Tq. Newasa, Dist. Ahmednagar',
  'roselandschool107@gmail.com',
  '9822070349'
);

CREATE TABLE admissions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  academic_year VARCHAR(20) NOT NULL,
  class_applied VARCHAR(30) NOT NULL,
  student_name VARCHAR(120) NOT NULL,
  date_of_birth DATE NOT NULL,
  gender ENUM('Female', 'Male', 'Other') NOT NULL,
  blood_group VARCHAR(10) NULL,
  aadhaar_number VARCHAR(12) NULL,
  previous_school VARCHAR(160) NULL,
  father_name VARCHAR(120) NOT NULL,
  mother_name VARCHAR(120) NOT NULL,
  parent_mobile VARCHAR(15) NOT NULL,
  parent_email VARCHAR(120) NULL,
  address TEXT NOT NULL,
  transport_required ENUM('Yes', 'No') NOT NULL DEFAULT 'No',
  last_class_passed VARCHAR(40) NULL,
  status ENUM('New', 'Contacted', 'Approved', 'Rejected') NOT NULL DEFAULT 'New',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_admissions_class_year (academic_year, class_applied),
  INDEX idx_admissions_status (status),
  INDEX idx_admissions_mobile (parent_mobile)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cms_content (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  content_key VARCHAR(80) NOT NULL,
  content_value TEXT NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cms_content_key (content_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO cms_content (content_key, content_value) VALUES
('brand_tagline', 'Learning with Joy'),
('hero_eyebrow', 'Pre-primary to Junior College'),
('hero_title', 'A cheerful school for strong basics, confident children, and bright futures.'),
('hero_text', 'ROSELAND SCHOOL supports early learning, primary foundations, secondary growth, and higher secondary preparation in a caring campus environment.'),
('hero_primary_button', 'Apply for Admission'),
('hero_call_button', 'Call 9822070349'),
('admission_badge_title', 'Admissions Open'),
('admission_badge_text', 'Nursery to Class XII'),
('stat_1_title', 'Joyful'),
('stat_1_text', 'Activity-based learning for young children'),
('stat_2_title', 'Focused'),
('stat_2_text', 'Strong reading, writing, maths and science basics'),
('stat_3_title', 'Safe'),
('stat_3_text', 'Parent-friendly communication and student care'),
('about_eyebrow', 'About Us'),
('about_title', 'Built for children from their first classroom steps to board preparation.'),
('about_text', 'ROSELAND SCHOOL, Handinimgaon, focuses on age-appropriate academics, discipline, creativity, sports, values, and personal attention. The website and admission system are intentionally simple so parents can enquire and apply without confusion.'),
('programs_eyebrow', 'Classes Offered'),
('programs_title', 'One school path from pre-primary to higher secondary.'),
('preprimary_title', 'Pre-primary'),
('preprimary_text', 'Nursery, LKG and UKG with stories, rhymes, play, motor skills and early numbers.'),
('primary_title', 'Primary'),
('primary_text', 'Class I to V with strong language, maths, environmental studies and activities.'),
('secondary_title', 'Secondary'),
('secondary_text', 'Class VI to X with structured academics, practice, projects and exam readiness.'),
('junior_title', 'Junior College'),
('junior_text', 'Class XI and XII admission support with the essential student and parent details.'),
('gallery_eyebrow', 'Campus Life'),
('gallery_title', 'Bright classrooms, useful labs, and calm learning spaces.'),
('gallery_1_caption', 'Smart Classrooms'),
('gallery_2_caption', 'Computer Room'),
('gallery_3_caption', 'Library'),
('gallery_4_caption', 'Laboratory'),
('admission_eyebrow', 'Admission Form'),
('admission_title', 'Keep it simple: only details needed for school admission.'),
('contact_eyebrow', 'Contact'),
('contact_title', 'Visit or contact ROSELAND SCHOOL.'),
('school_address', '152/1, Handinimgaon, Tq. Newasa, Dist. Ahmednagar'),
('school_email', 'roselandschool107@gmail.com'),
('school_phone', '9822070349');
