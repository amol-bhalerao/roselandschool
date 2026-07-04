# ROSELAND SCHOOL Website

Clean school website and admission enquiry setup for:

- **School:** ROSELAND SCHOOL
- **Address:** 152/1, Handinimgaon, Tq. Newasa, Dist. Ahmednagar
- **Email:** roselandschool107@gmail.com
- **Phone:** 9822070349

## What is included

- `public/` — pre-primary, primary, secondary and junior college website.
- `api/` — PHP admission enquiry endpoint.
- `database/roseland_school.sql` — fresh MySQL database setup.

## Local setup with XAMPP

1. Copy this project folder into `htdocs`, or point Apache to this folder.
2. Start Apache and MySQL from XAMPP.
3. Import the fresh database:

   ```powershell
   C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS u441114691_roseland CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
   C:\xampp\mysql\bin\mysql.exe -u root u441114691_roseland < database\roseland_school.sql
   ```

4. Open the website:

   ```text
   http://localhost/roseland-school/public/
   ```

## Database configuration

The default database settings are in `api/config.php`:

- Host: `localhost`
- Database: hosting database name
- User: hosting database user
- Password: hosting database password

Update these values if your hosting server uses different MySQL credentials.

## Admission form fields

The admission form keeps only school-relevant fields:

- Academic year and class applied
- Student name, date of birth, gender, blood group and Aadhaar
- Previous school and last class passed, where applicable
- Father/guardian name, mother name, mobile and email
- Address, transport requirement and declaration
