UPDATE carousel_slides SET image_url = REPLACE(image_url, '.jpeg', '.jpg');
UPDATE gallery_items SET url = REPLACE(url, '.jpeg', '.jpg');
UPDATE site_chrome SET header_json = REPLACE(header_json, '.jpeg', '.jpg'), footer_json = REPLACE(footer_json, '.jpeg', '.jpg');
UPDATE site_home SET content_json = REPLACE(content_json, '.jpeg', '.jpg');
UPDATE posts SET cover_image_url = REPLACE(cover_image_url, '.jpeg', '.jpg');
