 # wistia-to-vimeo
Wistia makes it hard to leave. Vimeo makes it hard to arrive. Migrate with ease.

`php 1-wistia.php`
- Consumes Wistia data via API into a single JSON file; sleeps between requests to avoid API rate limiting
- Renames files from Wistia's XXXXXXXXXXXX.bin using {Video Title}.{appropriate extension for contentType from Wistia API].
- Outputs a CSV spreadsheet formatted like https://vimeoenterprise.helpscoutdocs.com/article/827-migrating-content-to-vimeo

`php 2-vimeo.php`
- Transfers video files from Wistia to Vimeo via 'pull upload'
- Tags videos on Vimeo
- Recreates folders on Vimeo
- Moves videos into appropriate Vimeo folders
- Outputs a CSV spreadsheet which associates old Wistia ID with new Vimeo ID

## Dependencies
- Download the official PHP library for the Vimeo API: https://github.com/vimeo/vimeo.php
- Extract to create a `vimeo.php` subfolder alongside the files here (or update the path in `config.php`)
