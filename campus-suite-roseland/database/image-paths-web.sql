UPDATE carousel_slides SET image_url = REPLACE(REPLACE(image_url, '.jpeg', '-web.jpg'), '.jpg', '-web.jpg') WHERE image_url LIKE '/assets/images/roseland/%';
UPDATE gallery_items SET url = REPLACE(REPLACE(url, '.jpeg', '-web.jpg'), '.jpg', '-web.jpg') WHERE url LIKE '/assets/images/roseland/%';
UPDATE site_chrome SET header_json = REPLACE(REPLACE(header_json, '.jpeg', '-web.jpg'), '.jpg', '-web.jpg'), footer_json = REPLACE(REPLACE(footer_json, '.jpeg', '-web.jpg'), '.jpg', '-web.jpg');
UPDATE site_home SET content_json = REPLACE(REPLACE(content_json, '.jpeg', '-web.jpg'), '.jpg', '-web.jpg');
UPDATE posts SET cover_image_url = REPLACE(REPLACE(cover_image_url, '.jpeg', '-web.jpg'), '.jpg', '-web.jpg') WHERE cover_image_url LIKE '/assets/images/roseland/%';
