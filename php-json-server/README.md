# PHP JSON Server

This directory stores the JSON database that powers the `/api` endpoint. The PHP code lives in `www/api/index.php` and uses the `zlob/php-json-server` Composer package.

The JSON file in `db/db.json` acts as the data source. Feel free to edit or replace it while the containers are stopped. The file is locked during requests to avoid concurrent write issues.
