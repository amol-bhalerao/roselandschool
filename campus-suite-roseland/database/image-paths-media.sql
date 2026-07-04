UPDATE carousel_slides SET image_url = REPLACE(image_url, '/assets/images/roseland/', '/media/roseland/');
UPDATE gallery_items SET url = REPLACE(url, '/assets/images/roseland/', '/media/roseland/');
UPDATE site_chrome SET header_json = REPLACE(header_json, '/assets/images/roseland/', '/media/roseland/'), footer_json = REPLACE(footer_json, '/assets/images/roseland/', '/media/roseland/');
UPDATE site_home SET content_json = REPLACE(content_json, '/assets/images/roseland/', '/media/roseland/');
UPDATE posts SET cover_image_url = REPLACE(cover_image_url, '/assets/images/roseland/', '/media/roseland/') WHERE cover_image_url LIKE '/assets/images/roseland/%';
