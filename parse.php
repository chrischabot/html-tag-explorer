<?php

/*
 * Exception class used to communicate errors parsing the url
 */
class highlightException extends Exception {}

/**
 * Semi-formally declared response format
 */
class highlightResponse {
	public $error = false;
	public $html;
	public $tags;

	public function setError($error) {
		$this->error = $error;
	}

	public function setHtml($html) {
		$this->html = $html;
	}

	public function setTags($tags) {
		$this->tags = $tags;
	}
}


/*
 * Highlight class takes a URL to highlight.
 * 
 * The resulting html will have span elements around tags, attributes, and values.   
 * 
 * Using the class:
 * $highlight = new highlight();
 * try {
 * 	$highlight->parse($myUrl);
 * 	$tags = $highlight->getTags());
 * 	$highlightedHtml = $highlight->getHTML());
 * } catch (highlightException $e) {
 * 	echo "Error parsing: " . $e->getMessage();
 * }
 */
class highlight {
	private $tags = array();
	private $url = null;
	private $html = null;
	
	
	public function getHTML() {
		return $this->html;
	}
	
	
	public function getTags() {
		return $this->tags;
	}
	

	public function parse($url) {
		if (empty($url)) {
			throw new highlightException("No url specified");
		}
		// protocol section of the url is omited, add it
		if (substr($url, 0, 4) != 'http' && strpos($url, '://') === false) {
			$url = 'http://' . $url;
		}
		// verify it's a valid URL
		if (filter_var($url, FILTER_VALIDATE_URL) === false) {
			throw new highlightException("The URL isn't in a valid format ($url)");
		}
		// URL looks good, attempt to fetch and process it
		$this->url = $url;
		if (($this->html = @file_get_contents($url)) === false) {
			throw new highlightException("There was an error fetching the url");
		}
		$this->formatHTML();
		$this->parse_html();
	}
	
	
	private function addTag($tag) {
		$this->tags[$tag] = isset($this->tags[$tag]) ? $this->tags[$tag] + 1 : 1;
	}

	
	private function formatHTML() {
		// Format the HTML using Tidy so it's easier to visually explore in the browser 
		$this->html = tidy_parse_string($this->html, array(
				'indent' => TRUE,
				'wrap' => 200
		));
		$this->html = htmlentities($this->html);
	}


	/**
	 * parse formats the html string by adding <span> tags around the tags, attributes and attribute values
	 * as well as compiling a list of all the tag types found and how often they're encountered
	 *
	 * this implementation uses preg_replace_callback with a callback function to modify the content because
	 * preg_replace has a known security issue (ie <p>{${eval($_GET[php_code])}}</p> type abritrary code execution)
	 */
	private function parse_html() {
		$patterns = array(
				'(&lt;[a-z].*?&gt;)', // open html tag
				'(&lt;/[a-z].*?&gt;)' // close html tag
		); 
		$this->html = preg_replace_callback($patterns, array($this, 'tagCallback'), $this->html);
		// Sort the tags in alphabetical order
		ksort($this->tags);
	}


	private function tagCallback($matches) {
		$tag_end = (strpos($matches[0], ' ') !== false) ? strpos($matches[0], ' ') : strpos($matches[0], '&gt;');
		$tag = strtolower(substr($matches[0], 4, $tag_end - 4));
		if ($tag[0] == '/') {
			$tag = substr($tag, 1);
		} else {
			$this->addTag($tag);
		}
		// highlight the attributes and values of the tag being processed
		$patterns = array(
				'(\s[a-z].*?=)',                 // match attributes
				'(&quot;[a-zA-Z0-9\/].*?&quot;)' // match values
		);
		$matches[0] = preg_replace_callback($patterns, array($this, 'attributeCallback'), $matches[0]);
		return "<span class=\"tag tag_{$tag}\">" . strtolower($matches[0]) . "</span>";
	}

	
	private function attributeCallback($matches) {
		$class = (strpos($matches[0], '=') !== false) ? 'attribute' : 'value';
		return "<span class=\"$class\">{$matches[0]}</span>";
	}
}
//EOC highlight



/*** main execution ***/

// adding hundreds of <span> elements tends to bloat up html size,
// but text is highly compressable so lets take advantage
ob_start('ob_gzhandler');

$response = new highlightResponse();
if (isset($_GET['url'])) {
	if ($_GET['url'] == 'parse.php') {
		$response->setHtml(highlight_file('parse.php', true));	
	} else {
		try {
			$highlight = new highlight();
			$highlight->parse($_GET['url']);
			$response->setTags($highlight->getTags());
			$response->setHtml($highlight->getHTML());
		} catch (highlightException $e) {
			$response->setError($e->getMessage());
		}
	}
} else {
	$response->setError('No url specified');
}

header('Content-Type: application/json');
echo json_encode($response);

