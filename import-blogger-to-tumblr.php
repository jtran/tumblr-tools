<?php
// Blogger to Tumblr Importing
// by Jonathan Tran
// 6 June 2008
//
// See GitHub for changes http://github.com/jtran/tumblr-tools/tree
//
// This code is free to use, modify, and distribute;
// just give credit to its author(s).
//
//
// The code that posts to Tumblr is based off of the
// Tumblr API Sample from http://www.tumblr.com/api
//
// The code to parse xml was modified from Bobulous Central
// http://www.bobulous.org.uk/coding/php-xml-feeds.html
//
// The form color scheme is loosely based on Wufoo's
// Free Template Gallery http://wufoo.com/gallery/designs/44/#s3
//
// Thank you!  I couldn't have done it without you.
//

error_reporting(E_ALL|E_STRICT);
date_default_timezone_set('America/New_York');

// Allow this script to run a while.
define('MAX_RUN_TIME', 1800);	// in seconds
if (ini_get('max_execution_time') < MAX_RUN_TIME) {
	ini_set('max_execution_time', MAX_RUN_TIME);
}

require('xml_regex.php');


//////////////////////////////////////////////////
// Begin Util
//////////////////////////////////////////////////

// Get the POST value with the given key, or NULL
// if it was not set.
function getPost($k) {
	return isset($_POST[$k]) ? $_POST[$k] : NULL;
}

function getUrlContents($url) {
	$c = curl_init();
	curl_setopt($c, CURLOPT_URL, $url);
	curl_setopt($c, CURLOPT_HEADER, false);
	curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
	$contents = curl_exec($c);
	curl_close($c);
	
	return $contents;
}

// Written because html_entity_decode($s, ENT_QUOTES)
// was not converting &apos; at all.  Not sure why.
function unhtmlentities($s) {
	$s = html_entity_decode($s, ENT_COMPAT);
	$s = str_replace('&apos;', "'", $s);
	return $s;
}

// Make an untrusted string safe for displaying,
// and optionally truncate it.
function dispValidate($s, $truncate = TRUE) {
	if ($truncate) {
		$s = substr($s, 0, 30) . ((strlen($s) > 30) ? '...' : '');
	}
	return htmlentities($s, ENT_QUOTES);
}

class HtmlSafeException extends Exception {}

//////////////////////////////////////////////////
// End Util
//////////////////////////////////////////////////

// User input
// Note: No validation.  Let cURL and Tumblr do the heavy lifting.
$tumblr_email    = getPost('email');
$tumblr_password = getPost('password');
$tumblr_group    = getPost('group');
$feed_url        = getPost('feed');
$extra_tags      = getPost('extra_tags');
// Set param preview=1 to do everything except send to Tumblr.
$preview         = '1' === (isset($_GET['preview']) ? $_GET['preview'] : getPost('preview'));
// Set param debug=1 to display extra debug info.
$debug           = '1' === (isset($_GET['debug']) ? $_GET['debug'] : getPost('debug'));
// Set param autocorrect=0 to not try to infer the correct URL.
$autocorrect     = '0' !== (isset($_GET['autocorrect']) ? $_GET['autocorrect'] : getPost('autocorrect'));

// If the feed url starts with feed://, switch it to http://
if (strpos($feed_url, 'feed://') === 0) {
	$feed_url = 'http://' . substr($feed_url, strlen('feed://'));
}

// Creates a new Tumblr post
function createTextPost($entry, $try) {
	// Exponential back-off, capped at 1 minute.
	sleep(min(pow(2, $try), 60));
	
	// Data for new record
	$tags = join(',', $entry['tags']);
	$tags .= ((empty($tags)) ? '' : ',') . $GLOBALS['extra_tags'];
	$data = array(
			'email'     => $GLOBALS['tumblr_email'],
			'password'  => $GLOBALS['tumblr_password'],
			'type'      => 'regular',
			'title'     => $entry['title'],
			'body'      => $entry['body'],
			'date'      => $entry['timestamp'],
			'tags'      => $tags,
			'format'    => 'html',
			'generator' => 'http://plpatterns.tumblr.com/'
	);
	if (!empty($GLOBALS['tumblr_group'])) {
		$data['group'] = $GLOBALS['tumblr_group'];
	}
	
	// Prepare POST request
	$request_data = http_build_query($data);
	
	// Show extra debug info.
	if ($GLOBALS['debug']) {
		$str_data = print_r($data, TRUE);
		echo dispValidate($str_data, FALSE);
		echo "<br />\n<br />\n";
	}
	
	// Bail if we are in preview mode.
	if ($GLOBALS['preview']) return TRUE;
	
	// Send the POST request with cURL
	$c = curl_init('http://www.tumblr.com/api/write');
	curl_setopt($c, CURLOPT_POST, true);
	curl_setopt($c, CURLOPT_POSTFIELDS, $request_data);
	curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
	$result = curl_exec($c);
	$status = curl_getinfo($c, CURLINFO_HTTP_CODE);
	curl_close($c);
	
	// Check for success
	if ($status == 201) {
		return $result;
	} else if ($status == 403) {
		throw new Exception('Bad email or password', $status);
	} else if ($status >= 400 && $status < 500) {
		throw new Exception("Error $status: $result\n", $status);
	} else {
		if ($try < 20) {
			// Tumblr failed; retry.
			createTextPost($entry, $try + 1);
		} else {
			throw new Exception("Error $status: $result\n", $status);
		}
	}
}

function getBlogFromAtomFeed($xml, $url) {
	$blog = array(
		'title' => value_in('title', $xml),
		'entries' => array()
	);
	
	$entries = element_set('entry', $xml);
	if ($entries === NULL || $entries === false) {
		throw new Exception("XML parse error: could not find post entries; check your feed URL and make sure it is publicly accessible.  You should be able to see your posts here: $url");
	}
	
	foreach ($entries as $entry) {
		$tags = array();
		$cats = element_set('category', $entry);
		if (!empty($cats)) {
			foreach ($cats as $cat) {
				$category = element_attributes('category', $cat);
				
				// Blogger puts tags in reverse order, so prepend like a queue
				// instead of pushing like a stack.  (Not that order really matters,
				// but some people might care.)
				array_unshift($tags, unhtmlentities($category['term']));
			}
		}
		
		// Check for summary.  Blogger only includes summary if content isn't.
		$entry_body = unhtmlentities(value_in('content', $entry));
		$entry_summary = value_in('summary', $entry);
		if (empty($entry_body) && !empty($entry_summary)) {
			throw new HtmlSafeException("It looks like your blog's feed settings are only showing summaries, and this is preventing me from seeing your full posts.  Change your <a href=\"http://www.google.com/support/blogger/bin/answer.py?hl=en&amp;answer=42662\" target=\"_blank\">blog posts feed settings</a> to \"Full\".");
		}
		
		$blog['entries'][] = array(
			'title' => unhtmlentities(value_in('title', $entry)),
			'timestamp' => date('Y-m-d H:i:s', strtotime(value_in('published', $entry))),
			'body' => $entry_body,
			'tags' => $tags
		);
	}
	
	return $blog;
}

function import() {
	// Check if info was actually submitted
	if (empty($GLOBALS['tumblr_email']) || empty($GLOBALS['tumblr_password'])) {
		echo "Tumblr email and password required.<br /><br />\n";
		return;
	}
	if (empty($GLOBALS['feed_url'])) {
		echo "Blogger Feed URL required.<br /><br />\n";
		return;
	}
	
	echo "Importing.  This could take a few minutes.<br /><br />Please stand by...<br /><br />\n";
	
	try {
		// Download the posts
		echo "Downloading feed.<br />\n";
		$url = $GLOBALS['feed_url'];
		
		// Convert Blogger URL to Blogger Posts Feed URL.
		if ($GLOBALS['autocorrect'] &&
		    1 == preg_match("#^http://[^/\.]+\.blogspot\.com/$#", $url)) {
			$url .= 'feeds/posts/default';
		}
		
		// Blogger doesn't return all posts by default,
		// so we need to specifically ask for them all.
		if (strpos($url, 'max-results=') === FALSE) {
			$url .= (strpos($url, '?') === FALSE) ? '?' : '&';
			$url .= 'max-results=' . pow(2, 30);
		}
		
		$xml = getUrlContents($url);
		if (empty($xml)) {
			throw new Exception("Unable to download feed contents; check that the URL of your feed is correct and publicly accessible.  You should be able to see your posts here: $url");
		}
		
		// Parse it
		$blog = getBlogFromAtomFeed($xml, $url);
		
		echo 'Found: ' . dispValidate($blog['title']) . "<br />\n";
		
		// Create a new post for each old post
		$n = count($blog['entries']);
		for ($i = count($blog['entries']) - 1; $i >= 0; $i--) {
			$entry = $blog['entries'][$i];
			echo 'Importing: ' . dispValidate($entry['title']) . "<br />\n";
			createTextPost($entry, 0);
		}
		
		echo "<br />\nDone.  Imported $n " . (($n == 1) ? 'post' : 'posts') . ".<br />\n";
		
	} catch (HtmlSafeException $e) {
		echo "Importing failed: " . $e->getMessage() . "<br /><br />\n";
		echo "Having trouble?  Your question may already be answered <a href=\"http://plpatterns.com/post/37782942/moving-from-blogger-to-tumblr#disqus_thread\" target=\"_blank\">here</a>.<br /><br />\n";
	} catch (Exception $e) {
		echo "Importing failed: " . dispValidate($e->getMessage(), FALSE) . "<br /><br />\n";
		echo "Having trouble?  Your question may already be answered <a href=\"http://plpatterns.com/post/37782942/moving-from-blogger-to-tumblr#disqus_thread\" target=\"_blank\">here</a>.<br /><br />\n";
	}
}

?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<title>Import to Tumblr from Blogger</title>
<style type="text/css">
html {background-color: rgb(79, 99, 115);}
body {width: 32em; margin: 1em auto;}
form, div.content {background-color: rgb(239, 238, 209); padding: 20px; margin-top: 0;}
td {padding-bottom: 20px; vertical-align: top;}
h1, h2 {background-color: rgb(41, 56, 69); color: #fff; font-family: Tahoma, Verdana, sans-serif; margin-bottom: 0; padding: 5px 10px;}
h1 {font-size: x-large;}
h2 {font-size: large;}
h3 {font-size: large;}
hr {margin: 1.5em auto; width: 2.5cm}
pre {font-size: 90%;}
.optional {font-size: smaller; color: #888;}
.hidden {display: none;}
</style>
</head>

<body>

<h1>Import to Tumblr from Blogger</h1>

<?php
if (strtoupper($_SERVER['REQUEST_METHOD']) == 'POST') {
	echo '<div style="margin-top: 0; margin-bottom: 0; background-color: #000; color: #ccc; font-family: \'Courier New\', monospace; font-size: 80%; padding: 20px;">';
	import();
	
	//echo '<div class="alert">Remember to change your password!  You just sent it to a 3rd party.</div>';
	echo "</div>\n";
}

?>

<form method="post" action="<?php echo $_SERVER['PHP_SELF']; ?>">
<?php if ($preview) { ?>
  <input type="hidden" name="preview" value="1" />
<?php } ?>
<?php if ($debug) { ?>
  <input type="hidden" name="debug" value="1" />
<?php } ?>
<?php if (!$autocorrect) { ?>
  <input type="hidden" name="autocorrect" value="0" />
<?php } ?>
<table cellspacing="0">
<tr>
<td><label for="email">Tumblr Email:</label></td>
<td><input type="text" id="email" name="email" value="<?php echo $tumblr_email; ?>" /></td>
</tr>
<tr>
<td><label for="password">Tumblr Password:</label></td>
<td>
<input type="password" id="password" name="password" /> <a href="#sorry" style="font-size: 80%; position: relative; top: -0.5em;">1</a></td>
</tr>
<tr>
<td>
<label for="group">Tumblr Group:</label><br />
<span class="optional">(optional)</span>
</td>
<td>
<input type="text" id="group" name="group" value="<?php echo $tumblr_group; ?>" /><br />e.g. mygroup.tumblr.com for public, or 1495028 for private</td>
</tr>
<tr>
<td style="width: 125px"><label for="feed">Blogger Feed URL:</label></td>
<td>
<input type="text" id="feed" name="feed" value="<?php echo $feed_url; ?>" style="width: 20em;" /><br />e.g. http://<em>myblog</em>.blogspot.com/feeds/posts/default<br />
or http://www.blogger.com/feeds/<em>blogID</em>/posts/default<br/>
See <a href="#feed_options">More Feed Options</a> below.<br />
<br />
<span style="font-size: smaller"><a href="#" class="<?php if (!empty($extra_tags)) echo 'hidden'; ?>" onclick="document.getElementById('advanced').className=''; this.className='hidden'; document.getElementById('extra_tags').focus(); return false;">Advanced Options &dArr;</a></span>
</td>
</tr>
<tr id="advanced" class="<?php if (empty($extra_tags)) echo 'hidden'; ?>">
<td>
<label for="extra_tags">Extra Tags to Add to Posts:</label><br />
<span class="optional">(optional)</span>
</td>
<td><input type="text" id="extra_tags" name="extra_tags" value="<?php echo $extra_tags; ?>" /><br />
comma-delimited e.g. Blogger, My Old Blog, imported</td>
</tr>
<tr>
<td colspan="2" style="padding: 0"><input type="submit" value="Import to Tumblr" /></td>
</tr>
</table>
</form>

<h2>About</h2>
<div class="content">
This tool downloads posts from a <a href="http://www.blogger.com/">Blogger</a> post feed and imports them to <a href="http://www.tumblr.com/">Tumblr</a> Text posts.  It preserves the following information.

<ul>
<li>Post Title</li>
<li>Post Body</li>
<li>Post Date and Time</li>
<li>Post Labels (and their order)</li>
<li>Order of Posts in Tumblr Dashboard</li>
</ul>

<a href="http://plpatterns.com/post/37782942/moving-from-blogger-to-tumblr">More info</a> from the creator, <a href="http://jonnytran.com/">Jonathan Tran</a>.

<hr />

<h3><a name="feed_options">Feed Options</a></h3>

<p>To import only some posts, add "?max-results=2", for example, to the end of your Blogger Feed URL.  The number represents how many posts, so it doesn't have to be 2.</p>

<p>For example, my feed was:</p>
<pre><code>http://plpatterns.blogspot.com/feeds/posts/default</code></pre>

<p>To import the last 5 posts, I could enter the following as my feed URL:</p>
<pre><code>http://plpatterns.blogspot.com/feeds/posts/default?max-results=5</code></pre>

<p>See <a href="http://code.google.com/apis/blogger/developers_guide_protocol.html#RetrievingWithQuery">Blogger's Data API</a> for more options.</p>

<hr />

<a name="sorry">1</a>. I apologize for asking for your password <a href="http://www.codinghorror.com/blog/archives/001128.html">like this</a>.  But how else was I seriously supposed to make this useful to people?  You can <a href="http://plpatterns.com/post/37782942/moving-from-blogger-to-tumblr">download the source</a> and run it yourself, but not everyone can or wants to do that.  This is a real architectural problem with the web.  (Is <a href="http://oauth.net/">OAuth</a> the answer?)

</div>

<script type="text/javascript">
var gaJsHost = (("https:" == document.location.protocol) ? "https://ssl." : "http://www.");
document.write(unescape("%3Cscript src='" + gaJsHost + "google-analytics.com/ga.js' type='text/javascript'%3E%3C/script%3E"));
</script>
<script type="text/javascript">
var pageTracker = _gat._getTracker("UA-5428197-2");
pageTracker._trackPageview();
</script>
</body>
</html>
