<?php
// Do not buffer output - ignore these lines
ob_start();
ob_implicit_flush(true);
ob_end_flush();
echo "<pre>\n\n";

// Setup re. Vimeo > Settings > API access
$setup = [
	'client_id' => 'VIMEO_CLIENT_ID',
	'client_secret' => 'VIMEO_CLIENT_SECRET', 
	'access_token' => 'VIMEO_ACCESS_TOKEN',
];
$privacy = [
	'view' => 'unlisted', 
	'embed' => 'whitelist',
];
$embed_domains = [
	'example.com',
];

// Load SDK
require dirname(__FILE__) . '/../../core/lib/vimeo.php/autoload.php'; // Include the Vimeo PHP library
use Vimeo\Vimeo;

// Initialize the Vimeo client with your access token
$client = new Vimeo($setup['client_id'], $setup['client_secret'], $setup['access_token']);

// Read the CSV file
$csvFile = 'vimeo-input.csv'; // Replace with your CSV file path
$csvData = array_map('str_getcsv', file($csvFile));

// Extract the header row and add two more columns
$headerRow = $csvData[0];
$headerRow[] = "Wistia Video ID";
$headerRow[] = "Vimeo Video ID";

// Remove the header row from the data
array_shift($csvData);

// Start building output CSV
$updatedCsvData = [$headerRow];
$processedFolders = [];
$processedVideos = [];
$start_time_total = microtime(true);
foreach ($csvData as $index => $row) {
	$start_time = microtime(true);
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

    // Check if the video with the same title and description already exists in the current run
	$existingVideoID = null;
	foreach ($processedVideos as $video) {
		if ($video['name'] === $title && $video['description'] === $description) {
			$existingVideoID = $video['uri'];
			break;
		}
	}
	if ($existingVideoID) {
		$vimeoVideoID = $existingVideoID;
		echo "Video already processed: $vimeoVideoID\n";
	} else {
		// Check if the video with the same title and description already exists on Vimeo
		$videosList = $client->request('/me/videos', [], 'GET');
		$existingVideoID = null;

		foreach ($videosList['body']['data'] as $video) {
			if ($video['name'] === $title && $video['description'] === $description) {
				$existingVideoID = $video['uri'];
				break;
			}
		}

		if ($existingVideoID) {
			$vimeoVideoID = $existingVideoID;
			echo "Video already exists on Vimeo: $vimeoVideoID\n";
		} else {
			// Upload the video using the pull approach
			echo "Uploading";
			$videoResponse = $client->request('/me/videos', [
				'upload' => [
					'approach' => 'pull',
					'link' => $publicURL,
				],
				'name' => $title,
				'description' => $description,
				'privacy' => $privacy,
				'embed_domains' => $embed_domains,
				'embed' => ['buttons' => ['like' => false, 'watchlater' => false]],
				'content_rating' => 'safe',
			], 'POST');
			// todo: set thumbnail
			$videoData = $videoResponse['body'];
			$videoParts = explode('/', $videoData['uri']);
			$vimeoVideoID = end( $videoParts );
			$processedVideos[] = $videoData;

			// Check the video's status and wait until it's fully processed
			$videoStatus = $videoData['status'];
			$waits = 0;
			while ($videoStatus !== 'available') {
				$waits++;
				echo ".";
				// ob_flush();
	    		// flush(); // Send the output to the browser immediately
				// Sleep for a few seconds and then check the status again
				sleep(10);
				$videoInfo = $client->request("/me/videos/{$vimeoVideoID}", [], 'GET');
				$videoStatus = $videoInfo['body']['status'];
			}
			// echo "\n";
		}
	}

	$end_time = microtime(true);
	$execution_time = round($end_time - $start_time);
	echo " (".$execution_time." sec)\n";

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

	// Set Vimeo Folder ID (doesn't exist in the source CSV)
	$row[5] = $vimeoFolderID;
	// Add Vimeo Video ID to the output CSV (doesn't exist in the source CSV)
	$row[] = $vimeoVideoID;
	$updatedCsvData[] = $row;
	echo "\n";
}

// Define the path for the updated CSV file
$updatedCsvFile = 'vimeo-output.csv';

// Write the updated data to the CSV file using fputcsv
$csvFileHandle = fopen($updatedCsvFile, 'w');
// echo '<pre>'; print_r($updatedCsvData); echo '</pre>';
foreach ($updatedCsvData as $updatedRow) {
	fputcsv($csvFileHandle, $updatedRow);
}
fclose($csvFileHandle);

// Calculate the elapsed time
echo "New CSV linking Vimeo IDs with Wistia IDs written to $updatedCsvFile\n";
$end_time_total = microtime(true);
$execution_time_total = round($end_time_total - $start_time_total);
echo "DONE! ".(count($updatedCsvData)-1)." videos uploaded to Vimeo and transcribed in $execution_time_total seconds.";
echo "\n\n</pre>";
