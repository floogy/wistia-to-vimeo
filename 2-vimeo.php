<?php
ob_start();
ob_implicit_flush(true);
ob_end_flush();

include('config.inc.php');
include('functions.inc.php');

echo "<pre>\n\n";

// Load SDK
require $config['vimeo']['php_sdk_path']; // Include the Vimeo PHP library
use Vimeo\Vimeo;

// Initialize the Vimeo client with your access token
$client = new Vimeo($config['vimeo']['client_id'], $config['vimeo']['client_secret'], $config['vimeo']['access_token']);

// Read the CSV file
$csvFile = '1-wistia-valid.csv'; // Replace with your CSV file path
$csvData = array_map('str_getcsv', file($csvFile));

// Extract the header row and add two more columns
$headerRow = $csvData[0];
$headerRow[] = "Vimeo Video ID";

// Remove the header row from the data
array_shift($csvData);

// Create CSV files if non-existant
$csv_file_valid = '2-vimeo-valid.csv';
$csv_file_invalid = '2-vimeo-invalid.csv';
foreach ([$csv_file_valid, $csv_file_invalid] as $csv_file) {
	if (!file_exists($csv_file)) {
		$csv = fopen($csv_file, 'w');
		fputcsv($csv, $headerRow); // Write the header row to the CSV file
		fclose($csv);
	}
}

$processedFolders = [];
$processedVideos = [];
$start_time_total = microtime(true);
$count = 0;
foreach ($csvData as $index => $row) {
	$start_time = microtime(true);
	$skip = false;
	// echo '<pre>'; print_r($row); echo '</pre>';

	$publicURL = $row[0];

	if (empty(filter_var($publicURL, FILTER_VALIDATE_URL))) {
		echo "Skipped! Invalid Public URL\n";
		continue;
	}

	$title = $row[1];
	$description = $row[2];
	$tags = [];
	foreach (explode('|', $row[3]) as $tag) {
		if (!empty(trim($tag))) {
			$tags[] = ['name' => $tag];
		}
	}
	$thumbnailURL = $row[4];
	$vimeoFolderID = $row[5];
	$textTrackURL = $row[6];
	$folderName = $row[9];
	$wistiaFolderID = $row[10];
	$wistiaVideoID = $row[11];

	echo ($index + 1).". ".$title." \n";

	$csv_match_found_valid = isDuplicateWistiaVideoID($csv_file_valid, $wistiaVideoID);
	if (!empty($csv_match_found_valid)) {
		echo "Skipped - Valid match already found in ".$csv_file_valid."\n";
	}
	$csv_match_found_invalid = isDuplicateWistiaVideoID($csv_file_invalid, $wistiaVideoID);
	if (!empty($csv_match_found_invalid)) {
		echo "Skipped - Invalid match already found in ".$csv_file_invalid."\n";
	}
	if (empty($csv_match_found_valid) && empty($csv_match_found_invalid)) {
		$count++;

	    // Check if the folder exists, create if it doesn't
		if (empty($vimeoFolderID)) {
			if (array_key_exists($wistiaFolderID, $processedFolders)) {
				$vimeoFolderID = $processedFolders[$wistiaFolderID];
			} else {
	            // Check if the folder already exists on Vimeo
				$folders = $client->request('/me/projects', [], 'GET');
				$existingFolder = null;
				foreach ($folders['body']['data'] as $folder) {
					if ($folder['name'] === $folderName) {
						$existingFolder = $folder;
						break;
					}
				}

				if ($existingFolder) {
					$folderParts = explode('/', $existingFolder['uri']);
					$vimeoFolderID = end( $folderParts );
					$processedFolders[$wistiaFolderID] = $vimeoFolderID;
				} else {
	                // Create a new folder
					$folderResponse = $client->request('/me/projects', [
						'name' => $folderName,
					], 'POST');
					$folderData = $folderResponse['body'];
					$folderParts = explode('/', $folderData['uri']);
					$vimeoFolderID = end( $folderParts );
					echo "Created folder: $folderName\n";
					$processedFolders[$wistiaFolderID] = $vimeoFolderID;
				}
			}
		}

		$existingVideoID = null;

		if (!empty($existingVideoID)) {
			$vimeoVideoID = $existingVideoID;
		} else {
			$test_skip_upload = false;
			if (!empty($test_skip_upload)) {
				exit("Uploading stopped!\n");
			}
			// Upload the video using the pull approach
			echo "Uploading";
			$videoResponse = $client->request('/me/videos', [
				'upload' => [
					'approach' => 'pull',
					'link' => $publicURL,
				],
				'name' => $title,
				'description' => $description,
				'privacy' => $config['vimeo']['privacy'],
				'embed_domains' => $config['vimeo']['embed_domains'],
				'embed' => ['buttons' => ['like' => false, 'watchlater' => false]],
				'content_rating' => 'safe',
			], 'POST');
			// todo: set thumbnail
			$videoData = $videoResponse['body'];
			$videoParts = explode('/', $videoData['uri']);
			$vimeoVideoID = end( $videoParts );

			// Check the video's status and wait until it's fully processed
			$videoStatus = $videoData['status'];
			$waits = 0;
			while ($videoStatus !== 'available') {
				$waits++;
				if (in_array($videoStatus, ['transcode_starting', 'transcoding'])) {
					echo ".";
				} elseif (in_array($videoStatus, ['uploading_error'])) {
					echo $videoStatus.".";
					echo " (SKIPPED) ";
					$skip = true;
					break;
				} else {
					echo $videoStatus.".";
					echo " (UNKNOWN STATUS - SKIPPED) ";
					$skip = true;
					break;
				}
				// Sleep for a few seconds and then check the status again
				sleep(10);
				$videoInfo = $client->request("/me/videos/{$vimeoVideoID}", [], 'GET');
				$videoStatus = $videoInfo['body']['status'];
			}
			// echo "\n";

			// can shift the below below this condition if desirable to execute on existant files:

			$end_time = microtime(true);
			$execution_time = round($end_time - $start_time);
			echo " (".$execution_time." sec)\n";

			if (empty($skip)) {

				// Now, move the video to the desired folder
				if (!empty($vimeoFolderID)) {
					$client->request("/me/projects/{$vimeoFolderID}/videos/{$vimeoVideoID}", [], 'PUT');
					echo "Moved to folder: $folderName\n";
				}

				// Apply Tags
				if (count($tags)) {
					// echo "/videos/{$vimeoVideoID}/tags";
					// echo '<pre>'; print_r($tags); echo '</pre>';
					$tagResponse = $client->request("/videos/{$vimeoVideoID}/tags", $tags, 'PUT');
					// echo '<pre>'; print_r($tagResponse); echo '</pre>';
					echo "Tagged: ";
					$tag_name_arr = [];
					foreach ($tags as $tag) {
						$tag_name_arr[] = $tag['name'];
						// $tagResponse = $client->request("/me/videos/{$vimeoVideoID}/tags", ['name' => $tag['name']], 'PUT');
					}
					echo implode(", ", $tag_name_arr)."\n";
				}

			} // $skip
		}

		// Set Vimeo Folder ID (doesn't exist in the source CSV)
		$row[5] = $vimeoFolderID;
		// Add Vimeo Video ID to the output CSV (doesn't exist in the source CSV)
		$row[] = $vimeoVideoID;

		$csv_file = (empty($skip)? $csv_file_valid: $csv_file_invalid);
		$csv_match_found = isDuplicateWistiaVideoID($csv_file, $wistiaVideoID);
		if (empty($csv_match_found)) {
			$csv = fopen($csv_file, 'a');
			fputcsv($csv, $row);
			fclose($csv);
		} else {
			echo "Skipped writing - Match found in ".$csv_file." on ".$wistiaVideoID;
		}
		echo "\n";
	}
}

// Calculate the elapsed time
echo "New CSV linking Vimeo IDs with Wistia IDs written to $csv_file_valid\n";
$end_time_total = microtime(true);
$execution_time_total = round($end_time_total - $start_time_total);
echo "DONE! ".$count." videos uploaded to Vimeo and transcribed in $execution_time_total seconds.";
echo "\n\n</pre>";
