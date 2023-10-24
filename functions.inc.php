<?php
function isDuplicateWistiaVideoID($csvFilePath, $wistiaVideoID) {
    // Initialize an array to store duplicates
    $duplicates = array();

    // Open the CSV file for reading
    if (($handle = fopen($csvFilePath, 'r')) !== false) {
        while (($data = fgetcsv($handle)) !== false) {
        	if ($data[11] == $wistiaVideoID) {
                return true;
            }
        }
        fclose($handle);
    }
    return false;
}

# strip all non-alphanumeric chars
function strtoalphanumeric($var, $char="-", $strip_non_spaces=false) {
	if ($strip_non_spaces) {
		$var = str_replace(" ", '-', $var);
		$var = preg_replace("/[^0-9a-zA-Z-]/", '', $var);
	} else {
		$var = preg_replace("/[^0-9a-zA-Z-]/", $char, $var);
	}
	$var = trim($var);
	return $var;
}
