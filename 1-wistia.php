<?php
include('config.inc.php');
include('functions.inc.php');

/*
WHAT THIS SCRIPT DOES:

1. Consumes Wistia data via API into a single JSON file; sleeps between requests to avoid API rate limiting

2. Renames files from Wistia's XXXXXXXXXXXX.bin using {Video Title}.{appropriate extension for contentType from Wistia API].

3. Outputs a CSV spreadsheet formatted like https://vimeoenterprise.helpscoutdocs.com/article/827-migrating-content-to-vimeo

Note: Each item gets three Tags on Vimeo, as recommended in the article linked above.
- Wistia Project Name
- Wistia hashed Video ID
- Wistia hashed Project ID
*/

// Usually no need to edit below this line :)
$skip_step_1 = true; // true = skip connecting to Wistia API and downloading JSON file; file exists
$sleep_seconds_between_api_calls = 3; // 5 works, less may
$start_at_page_no = 1; // edit if you had a partial run
$valid_content_types = [
    'video/dvd',
    'video/mp2t',
    'video/mp4',
    'video/mpeg',
    'video/quicktime',
    'video/x-flv',
    'video/x-m4v',
    'video/x-matroska',
    'video/x-ms-wmv',
]; // NULL for any - lowercase only

$json_file = '1-wistia.json';
$csv_file_valid = '1-wistia-valid.csv';
$csv_file_invalid = '1-wistia-invalid.csv';

echo "<pre>";
if (empty($skip_step_1)) {
    echo PHP_EOL . "Step 1: Connecting to API and downloading 100 items at time to " . $json_file . " (sleeping ".$sleep_seconds_between_api_calls." seconds between calls to avoid rate limit)..." . PHP_EOL . PHP_EOL;

    $page_count = ceil($config['wistia']['file_count']/100); // Wistia returns 100 per page
    for ($i=$start_at_page_no; $i <= $page_count; $i++) {
        $page = $i;
        $url = 'https://api.wistia.com/v1/medias.json?page=' . $page . '&sort_by=created';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . $config['wistia']['api_token'],
        ));
        $response = curl_exec($ch);
        if ($response === false) {
            echo 'Error: ' . curl_error($ch);
            $curl_failed = true;
        } else {

            // Append to JSON file
            $data = json_decode($response, true);
            $json = json_encode($data, JSON_PRETTY_PRINT);
            file_put_contents($json_file, $json, FILE_APPEND);

            // Fix that we have multiple JSON objects in the file:
            $json_invalid = file_get_contents($json_file); // Read invalid JSON file with multiple blobs
            $json_valid = preg_replace('/\]\s*\[\s*/', ',', $json_invalid); // Use regular expressions to replace `] [` with `,` ignoring spaces or line breaks.
            file_put_contents($json_file, $json_valid); // Write the corrected JSON to the output file.

            // Just for display purposes
            echo PHP_EOL . "curl https://api.wistia.com/v1/medias.json?page=" . $page . "&sort_by=created -H \"Authorization: Bearer " . $config['wistia']['api_token'] . "\" >> " . $json_file . PHP_EOL;
            echo PHP_EOL . 'Page ' . $page . ': ' . count($data) . ' medias' . PHP_EOL;
        }

        curl_close($ch);

        if (!empty($curl_failed)) {
            die(PHP_EOL . PHP_EOL . "Sorry, connection not established to API.");
        }

        sleep($sleep_seconds_between_api_calls);
    }

} // $skip_step_1

/*
 * NB: Split the script here if you need to only convert JSON to CSV
 */

echo PHP_EOL . "=============================================" . PHP_EOL;

echo PHP_EOL . "Step 2: Generating `".$csv_file_valid."` and  `".$csv_file_invalid."` spreadsheet formatted like https://vimeoenterprise.helpscoutdocs.com/article/827-migrating-content-to-vimeo" . PHP_EOL;


// Load the JSON data from the file
$json = file_get_contents($json_file);

// Decode the JSON data into an array
$data = json_decode($json, true);

// Create CSV files
foreach ([$csv_file_valid, $csv_file_invalid] as $csv_file) {
    if (!file_exists($csv_file)) {
        $csv = fopen($csv_file, 'w');
        // Write the header row to the CSV file
        fputcsv($csv, array('Source URL', 'Title', 'Description', 'Tags', 'Thumbnail URL', 'Vimeo Folder ID', 'Text Track URL', 'Privacy', 'Content Type', 'Folder Name', 'Wistia Folder ID', 'Wistia Video ID'));
        fclose($csv);
    }
}

// Loop through each item in the JSON data
$files_per_content_type = [];
foreach ($data as $item) {
    // Get the values for each column from the JSON data
    $sourceUrl = $item['assets'][0]['url'];
    $title = $item['name'];
    $description = $item['description'];
    // $tags = $item['hashed_id']."|".$item['project']['hashed_id'].(!empty($item['section'])? "|".$item['section']: '');
    $tags = (!empty($item['section'])? $item['section']: '');
    $thumbnailUrl = $item['thumbnail']['url'];
    $wistiaFolderID = $item['project']['id'];
    $textTrackUrl = '';
    $privacy = 'unlisted';
    $folderName = $item['project']['name'];
    $contentType = $item['assets'][0]['contentType'];
    $wistiaVideoID = $item['hashed_id'];

    // Remove line breaks from description
    $description = str_replace(['
',"\n"], '<br>', $description);
    $description = str_replace(',"\n",', ',"",', $description);
    $description = str_replace('\n', "\n", $description);
    $description = str_replace('&nbsp;', " ", $description);
    if ($description == '<br>') {
        $description = "";
    }

    // Define the MIME types and their corresponding file extensions
    $mime_types = array(
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'htm' => 'text/html',
        'html' => 'text/html',
        'php' => 'text/html',
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'swf' => 'application/x-shockwave-flash',
        'flv' => 'video/x-flv',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'gif' => 'image/gif',
        'bmp' => 'image/bmp',
        'ico' => 'image/vnd.microsoft.icon',
        'tiff' => 'image/tiff',
        'tif' => 'image/tiff',
        'svg' => 'image/svg+xml',
        'svgz' => 'image/svg+xml',
        'zip' => 'application/zip',
        'rar' => 'application/x-rar-compressed',
        'exe' => 'application/x-msdownload',
        'msi' => 'application/x-msdownload',
        'cab' => 'application/vnd.ms-cab-compressed',
        'webm' => 'video/webm',
        'ogv' => 'video/ogg',
        'oga' => 'audio/ogg',
        'ogg' => 'audio/ogg',
        'mp3' => 'audio/mpeg',
        'qt' => 'video/quicktime',
        'swf' => 'application/x-shockwave-flash',
        'flv' => 'video/x-flv',
        'mp4' => 'video/mp4',
        'm3u8' => 'application/x-mpegURL',
        'ts' => 'video/MP2T',
        '3gp' => 'video/3gpp',
        'mov' => 'video/quicktime',
        'avi' => 'video/x-msvideo',
        'wmv' => 'video/x-ms-wmv',
        'pdf' => 'application/pdf',
        'psd' => 'image/vnd.adobe.photoshop',
        'ai' => 'application/postscript',
        'eps' => 'application/postscript',
        'ps' => 'application/postscript',
        'doc' => 'application/msword',
        'rtf' => 'application/rtf',
        'xls' => 'application/vnd.ms-excel',
        'ppt' => 'application/vnd.ms-powerpoint',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
    );

    // Get the file extension from the MIME type
    $file_extension = array_search($contentType, $mime_types);
    if (empty($file_extension)) {
        $file_extension = 'mp4';
    }

    // Remove the file extension from the source URL
    $sourceUrl = preg_replace('/\.[^.]+$/', '', $sourceUrl);

    // Append the file name with the appropriate extension based on the content type
    $file_name = strtoalphanumeric($item['name'], '|');
    for ($i=5;$i>=1;$i--) {
        $find = "";
        for ($j=$i;$j>=1;$j--) {
            $find .= "|";
        }
        $file_name = str_replace($find, '|', $file_name);
    }
    $file_name = str_replace('Where|s|', 'Where|is|', $file_name);
    $file_name = str_replace('|s|', 's|', $file_name);
    $file_name = str_replace('|', '_', $file_name);
    $file_name = str_replace(' ', '_', $file_name);
    $file_name = trim($file_name, '_');
    $file_name = trim($file_name);
    $sourceUrl .= '/' . $file_name . '.' . $file_extension;

    // Tally files per contentType
    // if (empty($files_per_content_type[ $contentType ])) {
    //  $files_per_content_type[ $contentType ] = 0;
    // }
    // $files_per_content_type[ $contentType ]++;

    // Write the content row to the valid or invalid CSV file
    $csv_file = (empty($valid_content_types) || in_array(strtolower($contentType), $valid_content_types)? $csv_file_valid: $csv_file_invalid);
    $csv_match_found = isDuplicateWistiaVideoID($csv_file, $wistiaVideoID);
    if (empty($csv_match_found)) {
        echo "('".$csv_file."', '".$wistiaVideoID."') = FALSE\n";
        $csv = fopen($csv_file, 'a');
        fputcsv($csv, array($sourceUrl, $title, $description, $tags, $thumbnailUrl, '', $textTrackUrl, $privacy, $contentType, $folderName, $wistiaFolderID, $wistiaVideoID));
        fclose($csv);
    } else {
        echo "('".$csv_file."', '".$wistiaVideoID."') = TRUE\n";
    }
    // if ($title == 'Balance-of-Powers-in-World-History') {
    //  exit('here');
    // }
}
// echo '<pre>'; print_r($files_per_content_type); echo '</pre>';

// Close the CSV file


echo PHP_EOL . "DONE" . PHP_EOL;

echo '</pre>';
