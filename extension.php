<?php
require_once __DIR__ . "/vendor/autoload.php";

use \fivefilters\Readability\Readability;
use \fivefilters\Readability\Configuration;

class Af_ReadabilityExtension extends Minz_Extension
{
	/** @var array<int,FreshRSS_Feed> */
	private array $feeds;
	/** @var array<int,FreshRSS_Category> */
	private array $categories;
	/** @var array<int,bool> */
	private array $configFeeds = [];
	/** @var array<int,bool> */
	private array $configCategories = [];
	private bool $configLoaded = false;

	public function init()
	{
		$this->registerHook('entry_before_insert', array($this, 'processArticle'));
		Minz_View::appendStyle($this->getFileUrl('style.css'));
	}

	/**
	 * @throws Minz_PermissionDeniedException
	 */
	public function processArticle(FreshRSS_Entry $article): FreshRSS_Entry
	{
		$this->loadConfigValues();
		$feedId = $article->feedId();

		$categoryId = $article->feed()?->category()?->id();

		if (!array_key_exists($feedId, $this->configFeeds)
			&& (null === $categoryId || !array_key_exists($categoryId, $this->configCategories))
		) {
			return $article;
		}

		$extractedContent = $this->extractContent($article->link());

		$contentTest = is_string($extractedContent) ? trim(strip_tags($extractedContent)) : null;

		if (!empty($contentTest)) {
			$article->_content((string)$extractedContent);
		}

		return $article;
	}

	/** @return array<int,FreshRSS_Feed> */
	public function getFeeds(): array
	{
		return $this->feeds;
	}

	/** @return array<int,FreshRSS_Category> */
	public function getCategories(): array
	{
		return $this->categories;
	}

	/**
	 * @throws Minz_PermissionDeniedException
	*/
	private function loadConfigValues(): void
	{
		if ($this->configLoaded) {
			return;
		}
		if (!class_exists('FreshRSS_Context', false)) {
			Minz_Log::warning('af-readability: FreshRSS_Context class not found');
			return;
		}
		try {
			$userConf = FreshRSS_Context::userConf();
		}
		catch(\Throwable $t) {
			Minz_Log::warning('af-readability: ' . $t->getMessage());
			return;
		}

		$this->configFeeds = $this->readConfigValue($userConf, 'ext_af_readability_feeds');
		$this->configCategories = $this->readConfigValue($userConf, 'ext_af_readability_categories');
		$this->configLoaded = true;
	}

	/** @return array<int,bool> */
	private function readConfigValue(FreshRSS_UserConfiguration $userConf, string $configKey): array
	{
		if('' === $configKey) {
			return [];
		}
		$value = $userConf->attributeString($configKey);
		if ($value == '') {
			return [];
		}

		$decoded = (array)json_decode($value, true);
		$result = [];
		foreach($decoded as $key => $param) {
			$result[(int)$key] = (bool) $param;
		}

		return $result;
	}

	public function getConfigFeeds(int $id): bool
	{
		return array_key_exists($id, $this->configFeeds);
	}

	public function getConfigCategories(int $id): bool
	{
		return array_key_exists($id, $this->configCategories);
	}

	/**
	 * @throws FreshRSS_Context_Exception
	 * @throws Minz_ConfigurationNamespaceException
	 * @throws Minz_PDOConnectionException
	 * @throws Minz_PermissionDeniedException
	 */
	public function handleConfigureAction()
	{
		$feedDAO = FreshRSS_Factory::createFeedDao();
		$catDAO = FreshRSS_Factory::createCategoryDao();
		$this->feeds = $feedDAO->listFeeds();
		$this->categories = $catDAO->listCategories(true,false);

		if (Minz_Request::isPost()) {
			$configFeeds = [];
			foreach ($this->feeds as $f) {
				if (Minz_Request::paramBoolean("feed_".$f->id())){
					$configFeeds[$f->id()] = true;
				}
			}

			$configCategories = [];
			foreach ($this->categories as $c) {
				if (Minz_Request::paramBoolean("cat_".$c->id())){
					$configCategories[$c->id()] = true;
				}
			}

			FreshRSS_Context::userConf()->_attribute('ext_af_readability_feeds', (string)json_encode($configFeeds));
			FreshRSS_Context::userConf()->_attribute('ext_af_readability_categories', (string)json_encode($configCategories));

			FreshRSS_Context::userConf()->save();
		}

		$this->loadConfigValues();
	}

	/**
	 * @throws Minz_PermissionDeniedException
	 */
	private function extractContent(string $url): bool|string|null
	{
		if(empty($url)) {
			return false;
		}

		$discourseContent = $this->extractDiscourseContent($url);
		if (is_string($discourseContent)) {
			return $discourseContent;
		}

		$result = $this->fetchUrl($url, [
			'Accept: text/*',
			'Content-Type: text/html'
		]);

		if ($result === null) {
			return false;
		}

		$response = $result['body'];
		$url = $result['effectiveUrl'];

		$document = new DOMDocument("1.0", "UTF-8");

		libxml_use_internal_errors(true);
		if (!$document->loadHTML('<?xml encoding="UTF-8">' . $response)) {
			libxml_clear_errors();
			return false;
		}
		libxml_clear_errors();

		if (null === $document->encoding || strtolower($document->encoding) !== 'utf-8') {
			$responseReplaced = preg_replace("/<meta.*?charset.*?\/?>/i", "", $response);
			$response = null !== $responseReplaced ? $responseReplaced : $response;
			if (empty($document->encoding)) {
				$response = mb_convert_encoding($response, 'utf-8');
			} else {
				$response = mb_convert_encoding($response, 'utf-8', $document->encoding);
			}
		}

		$discourseCrawlerContent = $this->parseDiscourseCrawlerDocument($document, $url);
		if (is_string($discourseCrawlerContent)) {
			return $discourseCrawlerContent;
		}

		try {
			$r = new Readability(new Configuration([
				'FixRelativeURLs' => true,
				'OriginalURL' => $url,
				'ExtraIgnoredElements' => ['template'],
			]));

			if ($r->parse($response)) {
				return $r->getContent();
			}
		}
		catch(\Throwable $t) {
			Minz_Log::warning('af-readability: Readability failed for ' . $url . ': ' . $t->getMessage());
			return false;
		}

		return false;
	}

	/**
	 * @return array{body:string,effectiveUrl:string}|null
	 */
	private function fetchUrl(string $url, array $headers): ?array
	{
		$ch = curl_init();
		if(false === $ch) {
			return null;
		}
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_USERAGENT, FRESHRSS_USERAGENT);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_ACCEPT_ENCODING, '');
		curl_setopt($ch, CURLOPT_TIMEOUT, 15);
		curl_setopt($ch, CURLOPT_MAXFILESIZE, 1024 * 1024 * 2);
		$response = curl_exec($ch);
		if (curl_errno($ch)) {
			curl_close($ch);
			return null;
		}
		$effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;
		curl_close($ch);

		if (!is_string($response)) {
			return null;
		}

		return [
			'body' => $response,
			'effectiveUrl' => $effectiveUrl,
		];
	}

	private function extractDiscourseContent(string $url): bool|string|null
	{
		$rssUrl = $this->buildDiscourseTopicRssUrl($url);
		if ($rssUrl === null) {
			return null;
		}

		$result = $this->fetchUrl($rssUrl, [
			'Accept: application/rss+xml, application/xml, text/xml, text/*',
			'Content-Type: application/rss+xml'
		]);

		if ($result === null) {
			Minz_Log::warning('af-readability: Discourse RSS fetch failed for ' . $rssUrl);
			return false;
		}

		$content = $this->parseDiscourseTopicRss($result['body'], $result['effectiveUrl']);
		if ($content === null) {
			Minz_Log::warning('af-readability: Discourse RSS parse returned no posts for ' . $result['effectiveUrl']);
			return false;
		}

		return $content;
	}

	private function buildDiscourseTopicRssUrl(string $url): ?string
	{
		$parts = parse_url($url);
		if (!is_array($parts)
			|| !isset($parts['scheme'], $parts['host'], $parts['path'])
		) {
			return null;
		}

		$path = rtrim($parts['path'], '/');
		if (str_ends_with($path, '.rss')) {
			return $this->buildUrl($parts, $path);
		}

		if (!preg_match('#^(.*/t/(?:[^/]+/)?\d+)(?:/\d+)?$#', $path, $matches)) {
			return null;
		}

		return $this->buildUrl($parts, $matches[1] . '.rss');
	}

	/**
	 * @param array<string,int|string> $parts
	 */
	private function buildUrl(array $parts, string $path): string
	{
		$port = isset($parts['port']) ? ':' . $parts['port'] : '';

		return $parts['scheme'] . '://' . $parts['host'] . $port . $path;
	}

	private function parseDiscourseTopicRss(string $rss, string $rssUrl): ?string
	{
		$document = new DOMDocument("1.0", "UTF-8");

		libxml_use_internal_errors(true);
		if (!$document->loadXML($rss, LIBXML_NOCDATA)) {
			libxml_clear_errors();
			return null;
		}
		libxml_clear_errors();

		$channel = $document->getElementsByTagName('channel')->item(0);
		if (!$channel instanceof DOMElement) {
			return null;
		}

		$title = $this->getFirstElementText($channel, 'title');
		$topicLink = $this->getFirstElementText($channel, 'link');
		$posts = [];

		foreach ($document->getElementsByTagName('item') as $item) {
			if (!$item instanceof DOMElement) {
				continue;
			}

			$content = $this->cleanDiscoursePostContent($this->getFirstElementText($item, 'description'));
			if (trim(strip_tags($content)) === '') {
				continue;
			}

			$posts[] = [
				'author' => $this->getFirstElementText($item, 'creator', 'http://purl.org/dc/elements/1.1/'),
				'content' => $content,
				'link' => $this->getFirstElementText($item, 'link'),
				'date' => $this->getFirstElementText($item, 'pubDate'),
			];
		}

		if (empty($posts)) {
			return null;
		}

		// Discourse topic RSS is newest-first; FreshRSS article content is easier to read oldest-first.
		$posts = array_reverse($posts);
		$html = '<article class="af-readability-discourse-topic">';

		if ($title !== '') {
			$titleHtml = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
			if ($topicLink !== '') {
				$html .= '<h1><a href="' . htmlspecialchars($topicLink, ENT_QUOTES, 'UTF-8') . '">' . $titleHtml . '</a></h1>';
			} else {
				$html .= '<h1>' . $titleHtml . '</h1>';
			}
		}

		foreach ($posts as $index => $post) {
			$postNumber = $index + 1;
			$author = htmlspecialchars($post['author'], ENT_QUOTES, 'UTF-8');
			$date = htmlspecialchars($post['date'], ENT_QUOTES, 'UTF-8');
			$link = htmlspecialchars($post['link'], ENT_QUOTES, 'UTF-8');
			$heading = '#' . $postNumber;

			if ($author !== '') {
				$heading .= ' - ' . $author;
			}
			if ($date !== '') {
				$heading .= ' - ' . $date;
			}

			$html .= '<section class="af-readability-discourse-post">';
			if ($link !== '') {
				$html .= '<h2><a href="' . $link . '">' . $heading . '</a></h2>';
			} else {
				$html .= '<h2>' . $heading . '</h2>';
			}
			$html .= $post['content'];
			$html .= '</section>';
		}

		$html .= '<p><a href="' . htmlspecialchars($rssUrl, ENT_QUOTES, 'UTF-8') . '">Topic RSS</a></p>';
		$html .= '</article>';

		return $html;
	}

	private function getFirstElementText(DOMElement $parent, string $tagName, ?string $namespace = null): string
	{
		if ($namespace !== null) {
			$nodes = $parent->getElementsByTagNameNS($namespace, $tagName);
		} else {
			$nodes = $parent->getElementsByTagName($tagName);
		}

		$node = $nodes->item(0);
		if (!$node instanceof DOMNode) {
			return '';
		}

		return trim($node->textContent);
	}

	private function parseDiscourseCrawlerDocument(DOMDocument $document, string $url): ?string
	{
		$xpath = new DOMXPath($document);
		$posts = $xpath->query(
			"//*[contains(concat(' ', normalize-space(@class), ' '), ' topic-body ')"
			. " and contains(concat(' ', normalize-space(@class), ' '), ' crawler-post ')]"
		);

		if (!$posts instanceof DOMNodeList || $posts->length === 0) {
			return null;
		}

		$title = $this->getDocumentTitle($document);
		$html = '<article class="af-readability-discourse-topic">';

		if ($title !== '') {
			$html .= '<h1><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">'
				. htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
				. '</a></h1>';
		}

		$postNumber = 0;
		foreach ($posts as $post) {
			if (!$post instanceof DOMElement) {
				continue;
			}

			$contentNode = $this->findFirstDescendantByClass($post, 'cooked');
			if (!$contentNode instanceof DOMElement) {
				continue;
			}

			$content = $this->cleanDiscoursePostContent($this->innerHtml($contentNode));
			if (trim(strip_tags($content)) === '') {
				continue;
			}

			$postNumber++;
			$postLink = $url . '#post_' . $postNumber;
			$html .= '<section class="af-readability-discourse-post">';
			$html .= '<h2><a href="' . htmlspecialchars($postLink, ENT_QUOTES, 'UTF-8') . '">#' . $postNumber . '</a></h2>';
			$html .= $content;
			$html .= '</section>';
		}

		$html .= '</article>';

		if ($postNumber === 0) {
			return null;
		}

		return $html;
	}

	private function getDocumentTitle(DOMDocument $document): string
	{
		$titleNode = $document->getElementsByTagName('title')->item(0);
		if (!$titleNode instanceof DOMNode) {
			return '';
		}

		return trim($titleNode->textContent);
	}

	private function findFirstDescendantByClass(DOMElement $parent, string $className): ?DOMElement
	{
		foreach ($parent->getElementsByTagName('*') as $element) {
			if (!$element instanceof DOMElement) {
				continue;
			}

			$classes = ' ' . preg_replace('/\s+/', ' ', $element->getAttribute('class')) . ' ';
			if (str_contains($classes, ' ' . $className . ' ')) {
				return $element;
			}
		}

		return null;
	}

	private function innerHtml(DOMNode $node): string
	{
		$html = '';
		foreach ($node->childNodes as $child) {
			$html .= $node->ownerDocument?->saveHTML($child) ?: '';
		}

		return $html;
	}

	private function cleanDiscoursePostContent(string $content): string
	{
		$content = preg_replace(
			'#<p>\s*<a\s+[^>]*>\s*(阅读完整话题|read full topic)\s*</a>\s*</p>#iu',
			'',
			$content
		);

		return trim($content ?? '');
	}
}
