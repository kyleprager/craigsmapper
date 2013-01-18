<?php
include ('simple_html_dom.php');

$area = 'sfbay';
$listingType = 'apa';
$numPagesToScrape = 1;

$arr = scrapeCraigsList($area, $listingType, $numPagesToScrape);
echo json_encode($arr);

function scrapeCraigsList($area, $listingType, $numPagesToScrape) {

	// connect to database and select table
	$con = mysql_connect("127.0.0.1", "root", "");
	if (!$con) {
		die('Could not connect: ' . mysql_error());
	}
	if (!mysql_select_db("craigs_mapper", $con)) {
		die('Could not connect: ' . mysql_error());
	}

	
	$objs = array();
	for ($i = 0; $i < $numPagesToScrape; $i++) {
		if ($i == 0)
			$html = file_get_html('http://' . $area . '.craigslist.org/' . $listingType);
		else
			$html = file_get_html('http://' . $area . '.craigslist.org/' . $listingType . '/index' . ($i * 100) . '.html');
		$arr = getMapPostings($html);
		$objs = array_merge($objs, $arr);
	}

	// throw all the postings into the database
	foreach ($objs as $obj) {
		$tmp = array('title', 'address', 'link');
		foreach($tmp as $word) {
			if (array_key_exists($word, $obj)) {
				$obj[$word] = addslashes($obj[$word]);
				$obj[$word] = "'" . $obj[$word] . "'";
			}
		}
		$keys = array_keys($obj);
		$values = array_values($obj);
		mysqlEscape($keys);
		mysqlEscape($values);
		$str = "INSERT INTO location (" . implode(", ", $keys) . ") VALUES (" 
				. implode(", ", $values) . ")";
		mysql_query($str);
	}
	// $sql_query = implode("\n", $sql);
	// mysql_query($sql_query);

	mysql_close($con);

	return $objs;
}

function mysqlEscape(&$strarr) {
	foreach ($strarr as &$str) {
		mysql_escape_string($str);
	}
	unset($str);
}

// gets all the craigslist postings from a listing page that have map links
function getMapPostings($html) {
	$objs = array();
	foreach ($html->find('html body.toc blockquote p.row') as $element) {

		$post = array();

		// scrape the actual listing itself
		$a = $element -> find('a', 0);
		$str2 = $a -> innertext;
		$post['title'] = $str2;
		$post['link'] = $a -> href;

		// split up string containing price, bdrms, sqft
		$txt = $element -> find('span.itemph', 0);
		$str = $txt -> innertext;
		$matches = preg_split('/ - /', $str);
		$info = preg_split('/ \/ /', $matches[0]);

		// get price, bedrooms, square footage (sqft)
		// very important to make sure this is a blank slate each time around
		if (count($info) > 0 && preg_match('/^\$/', $info[0])) {
			$post['price'] = intval(substr($info[0], 1));
		}
		if (count($info) > 1 && preg_match('/[0-9]+br$/', $info[1])) {
			$post['bedrooms'] = intval(substr($info[1], 0, strlen($info[1]) - 2));
		}
		if (count($matches) > 1 && strcmp(trim($matches[1]), "") != 0) {
			$post['sqft'] = intval(substr($matches[1], 0, strlen($matches[1]) - 8));
		}


		//**********GET LAT/LNG FROM GOOGLE************/
		// check for map address
		$results = mysql_query("SELECT 1 FROM location WHERE link='" . $post['link'] . "'");
		if (mysql_num_rows($results) == 0) { // check database for link already existing
			$arr = scrapePosting($a -> href);
			if ($arr) { // add post only if there's a map address
				$post = array_merge($arr, $post);
				array_push($objs, $post);
			}
		}

		//*********************************************/
		
		//********ERASE THIS**************/
		// $post['lat'] = 38;
		// $post['lng'] = -126;
		// array_push($objs, $post);
		//********************************/
		

	}
	return $objs;
}

function scrapePosting($url) {
	$html = file_get_html($url);
	$a = $html -> find('small a', 1);
	if ($a) {
		$loc = str_replace('http://maps.yahoo.com/maps_result?addr=', '', $a -> href);
		$loc = str_replace('http://maps.google.com/?q=loc%3A', '', $loc);
		$arr['address'] = trim(urldecode($loc));
		$addr = "http://maps.googleapis.com/maps/api/geocode/json?address=" . urlencode($arr['address']) . '&sensor=true';
		$jsonstr = file_get_contents($addr);
		$json = json_decode($jsonstr);
		if ($json -> status == 'OK') {
			$location = $json -> results[0] -> geometry -> location;
			$arr['lat'] = $location -> lat;
			$arr['lng'] = $location -> lng;
		} else {
			// echo $json -> status;
		}

		return $arr;
	}
	return false;
}
?>