<?php namespace Milkyway\SS;

class Director extends \Director implements \TemplateGlobalProvider
{
	public static function create_page($url, $type = 'Page', $values = [])
	{
		if (\SiteTree::get_by_link($url)) return;

		$page = \Object::create($type);

		foreach ($values as $f => $v)
			$page->$f = $v;

		$page->URLSegment = $url;

		$page->write();
		$page->publish('Stage', 'Live');

		\DB::alteration_message($page->Title . ' Page created', 'created');
	}

	public static function create_home_page()
	{
		$type = \ClassInfo::exists('HomePage') ? 'HomePage' : 'Page';

		self::create_page(
			\Config::inst()->get('RootURLController', 'default_homepage_link'),
			$type,
			[
				'Title'     => _t('Page.DEFAULT_HOME_TITLE', 'Home'),
				'MetaTitle' => '[site_config field=Title]',
				'Content'   => _t('Page.DEFAULT_HOME_CONTENT', '<p>This site is currently under construction. Please check back soon!</p>'),
				'Sort'      => 1
			]
		);
	}

	public static function create_default_error_page($code = 404, $values = [], $type = 'ErrorPage')
	{
		$page = \ErrorPage::get()->filter('ErrorCode', $code)->first();
		$pagePath = \ErrorPage::get_filepath_for_errorcode($code);

		if (!$page || !file_exists($pagePath)) {
			$page = \Object::create($type);

			foreach ($values as $f => $v)
				$page->$f = $v;

			$page->ErrorCode = $code;

			$page->write();
			$page->publish('Stage', 'Live');

			$response = self::test(self::makeRelative($page->Link()));
			$written = null;

			if ($fh = fopen($pagePath, 'w')) {
				$written = fwrite($fh, $response->getBody());
				fclose($fh);
			}

			if ($written)
				\DB::alteration_message($page->Title . ' Page created', 'created');
			else
				\DB::alteration_message(sprintf($page->Title . ' Page could not be created at %s. Please check permissions', $pagePath), 'error');
		}
	}

	public static function ajax_response($rawData, $code = 200, $status = 'success')
	{
		$vars = [
			'status' => $status,
			'code'   => $code
		];

		if (!is_array($rawData) && !is_object($rawData)) {
			$data = ['Content' => $rawData];
			$vars['message'] = $rawData;
		} else {
			$data = $rawData;

			if (isset($rawData['Message']))
				$vars['message'] = $rawData['Message'];
			elseif (isset($rawData['message']))
				$vars['message'] = $rawData['message'];

			foreach ($data as $n => $v) {
				if ($v instanceof DBField)
					$data[$n] = $v->Nice();
			}
		}

		$vars['data'] = $data;

		$response = new \SS_HTTPResponse(json_encode($vars), $code, strtolower($status));
		$response->addHeader('Content-type', 'application/json');

		return $response;
	}

	public static function finish_and_redirect($raw, $code = 200, $status = 'success', $r = null, $c = null, $redirectTo = '')
	{
		if (!$c) $c = \Controller::curr();
		if (!$r && $c) $r = $c->Request;

		if ($r && $r->isAjax()) {
			if (!isset($raw['redirect']) && $redirectTo)
				$raw['redirect'] = $redirectTo;

			return self::ajax_response($raw, $code, $status);
		} elseif ($c) {
			$msg = $status;

			if (!is_array($raw) || !is_object($raw))
				$msg = $raw;
			else {
				if (isset($raw['Message']))
					$msg = $raw['Message'];
				elseif (isset($raw['message']))
					$msg = $raw['message'];
			}

			if (isset($raw['type']))
				$type = $raw['type'];
			else
				$type = $status == 'success' ? 'alert-success' : 'alert-error';

			if ($redirectTo)
				$c->redirect($redirectTo);
			else
				return $c->redirectBack();
		}

		return false;
	}

	private static $site_home = null; // home page

	// Get home page
	public static function homePage()
	{
		if (self::$site_home)
			return self::$site_home;

		$home = null;

		if (\ClassInfo::exists('HomePage'))
			$home = \DataObject::get_one('HomePage');

		if (!$home)
			$home = \SiteTree::get_by_link(\RootUrlController::get_homepage_link());

		if (!$home)
			$home = \Page::get()->first();

		self::$site_home = $home;

		return $home;
	}

	public static function isHomePage($page = null)
	{
		if (!$page && \Controller::curr() && (\Controller::curr() instanceof \ContentController))
			$page = \Controller::curr()->data();

		if($page && is_object($page))
			$page = $page->ID;

		return self::homePage() && $page ? $page == self::homePage()->ID : false;
	}

	public static function secureBaseHref()
	{
		return str_replace('http://', 'https://', self::absoluteBaseURL());
	}

	public static function nonSecureBaseHref()
	{
		return str_replace('https://', 'http://', self::absoluteBaseURL());
	}

	public static function baseWebsiteURL()
	{
		return trim(str_replace(['http://', 'https://', 'www.'], '', self::protocolAndHost()), ' /');
	}

	public static function protocol()
	{
		return parent::protocol();
	}

	public static function adminLink()
	{
		return \Controller::join_links(\Config::inst()->get('AdminRootController', 'url_base'));
	}

	public static function get_template_global_variables()
	{
		return [
			'secureBaseURL',
			'nonSecureBaseURL',
			'baseWebsiteURL',
			'protocol',
			'homePage',
			'isHomePage',
			'adminLink',
			'SiteConfig' => 'current_site_config',
			'siteConfig' => 'current_site_config',
		];
	}

	public static function create_view($controller, $url = '', $action = '')
	{
		if (\ClassInfo::exists('SiteTree')) {
			$page = \Page::create();

			$page->URLSegment = $url ? $url : $controller->Link();
			$page->Action = $action;
			$page->ID = -1;

			$controller = \Page_Controller::create($page);
		}

		return $controller;
	}

	public static function add_link_data($url, $data = [])
	{
		if (!count($data)) return $url;

		// Make sure data in url takes preference over data from email log
		if (strpos($url, '?') !== false) {
			list($newURL, $query) = explode('?', $url, 2);

			$url = $newURL;

			if ($query) {
				@parse_str($url, $current);

				if ($current && count($current))
					$data = array_merge($data, $current);
			}
		}

		if (count($data)) {
			$linkData = [];

			foreach ($data as $name => $value)
				$linkData[$name] = urlencode($value);

			$url = \Controller::join_links($url, '?' . http_build_query($linkData));
		}

		return $url;
	}

	// Convert the get vars into a query string, automatically eliminates the url get var
	public static function query_string($request = null)
	{
		if (!$request)
			$request = \Controller::curr()->Request;

		if (!$request) return '';

		$vars = $request->getVars();
		if (isset($vars['url'])) unset($vars['url']);

		return count($vars) ? '?' . http_build_query($vars) : '';
	}

	public static function current_site_config()
	{
		if (\Controller::curr() && (\Controller::curr() instanceof \ContentController) && \Controller::curr()->data() && $config = \Controller::curr()->data()->SiteConfig)
			return $config;

		return \SiteConfig::current_site_config();
	}

	public static function url() {
		$url  = isset( $_SERVER['HTTPS'] ) && 'on' === $_SERVER['HTTPS'] ? 'https' : 'http';
		$url .= '://' . $_SERVER['SERVER_NAME'];
		$url .= in_array( $_SERVER['SERVER_PORT'], array('80', '443') ) ? '' : ':' . $_SERVER['SERVER_PORT'];
		$url .= $_SERVER['REQUEST_URI'];
		return func_num_args() ? call_user_func_array(['Controller', 'join_links'], array_merge([$url], func_get_args())) : $url;
	}
}