<?php

namespace XF\Http;

class Request
{
	/**
	 * @var \XF\InputFilterer
	 */
	protected $filterer;

	protected $input;
	protected $files;
	protected $cookie;
	protected $server;

	protected $skipLogKeys = ['_xfToken'];

	protected $cookiePrefix = '';

	public static $googleIps = [
		'v4' => [
			'64.18.0.0/20',
			'64.233.160.0/19',
			'66.102.0.0/20',
			'66.249.80.0/20',
			'72.14.192.0/18',
			'74.125.0.0/16',
			'108.177.8.0/21',
			'172.217.0.0/19',
			'173.194.0.0/16',
			'207.126.144.0/20',
			'209.85.128.0/17',
			'216.58.192.0/19',
			'216.239.32.0/19',
		],
		'v6' => [
			'2001:4860:4000::/36',
			'2404:6800:4000::/36',
			'2607:f8b0:4000::/36',
			'2800:3f0:4000::/36',
			'2a00:1450:4000::/36',
			'2c0f:fb50:4000::/36'
		]
	];

	public static $cloudFlareIps = [
		'v4' => [
			'103.21.244.0/22',
			'103.22.200.0/22',
			'103.31.4.0/22',
			'104.16.0.0/12',
			'108.162.192.0/18',
			'131.0.72.0/22',
			'141.101.64.0/18',
			'162.158.0.0/15',
			'172.64.0.0/13',
			'173.245.48.0/20',
			'188.114.96.0/20',
			'190.93.240.0/20',
			'197.234.240.0/22',
			'198.41.128.0/17',
		],
		'v6' => [
			'2400:cb00::/32',
			'2405:8100::/32',
			'2405:b500::/32',
			'2606:4700::/32',
			'2803:f800::/32',
			'2c0f:f248::/32',
			'2a06:98c0::/29'
		]
	];

	protected $_remoteIp = null;

	public function __construct(\XF\InputFilterer $filterer,
		array $input = null, array $files = null, array $cookie = null, array $server = null
	)
	{
		$this->filterer = $filterer;

		if ($input === null)
		{
			$input = $_POST + $_GET;
		}
		if ($files === null)
		{
			$files = $_FILES;
		}
		if ($cookie === null)
		{
			$cookie = $_COOKIE;
		}
		if ($server === null)
		{
			$server = $_SERVER;
		}

		$this->input = $input;
		$this->files = $files;
		$this->cookie = $cookie;
		$this->server = $server;
	}

	public function setCookiePrefix($prefix)
	{
		$this->cookiePrefix = $prefix;
	}

	public function getCookiePrefix()
	{
		return $this->cookiePrefix;
	}

	public function get($key, $fallback = false)
	{
		$subParts = explode('.', $key);
		$key = array_shift($subParts);

		if (array_key_exists($key, $this->input))
		{
			$value = $this->input[$key];
		}
		else
		{
			return $fallback;
		}

		return $this->getSubValue($value, $subParts, $fallback);
	}

	public function exists($key)
	{
		$subParts = explode('.', $key);
		$key = array_shift($subParts);

		if (array_key_exists($key, $this->input))
		{
			$value = $this->input[$key];
		}
		else
		{
			return false;
		}

		while ($subParts)
		{
			if (!is_array($value))
			{
				return false;
			}

			$key = array_shift($subParts);
			if (array_key_exists($key, $value))
			{
				$value = $value[$key];
			}
			else
			{
				return false;
			}
		}

		return true;
	}

	public function getUser($key, $fallback = false)
	{
		return $this->get($key, $fallback);
	}

	protected function getSubValue($value, array $subParts, $fallback)
	{
		while ($subParts)
		{
			if (!is_array($value))
			{
				return $fallback;
			}

			$key = array_shift($subParts);
			if (array_key_exists($key, $value))
			{
				$value = $value[$key];
			}
			else
			{
				return $fallback;
			}
		}

		return $value;
	}

	public function filter($key, $type = null, $default = null)
	{
		if (is_array($key) && $type === null)
		{
			$output = [];
			foreach ($key AS $name => $value)
			{
				if (is_array($value))
				{
					$array = $this->get($name);
					if (!is_array($array))
					{
						$array = [];
					}
					$output[$name] = $this->filterer->filterArray($array, $value);
				}
				else
				{
					$output[$name] = $this->filter($name, $value);
				}
			}

			return $output;
		}
		else
		{
			$value = $this->get($key, $default);

			if (is_array($type))
			{
				if (!is_array($value))
				{
					$value = [];
				}

				return $this->filterer->filterArray($value, $type);
			}
			else
			{
				return $this->filterer->filter($value, $type);
			}
		}
	}

	/**
	 * @param $key string Input key to set - either 'keyName' or 'arrayName.subArrayName.keyName' etc.
	 * @param $value
	 */
	public function set($key, $value)
	{
		$parts = explode('.', $key);

		$var =& $this->input;
		while ($part = array_shift($parts))
		{
			$var =& $var[$part];
		}

		$var = $value;
	}

	public function getInputForLogs()
	{
		return $this->filterForLog($this->input);
	}

	public function filterForLog(array $data)
	{
		$skip = array_fill_keys($this->skipLogKeys, true);

		$filter = function(array $d) use ($skip, &$filter)
		{
			$output = [];
			foreach ($d AS $k => $v)
			{
				if (isset($skip[$k]) || strpos($k, 'password') !== false)
				{
					$output[$k] = '********';
				}
				else if (is_array($v))
				{
					$output[$k] = $filter($v);
				}
				else
				{
					$output[$k] = $v;
				}
			}

			return $output;
		};

		return $filter($data);
	}

	public function skipKeyForLogging($key)
	{
		$this->skipLogKeys[] = $key;
	}

	/**
	 * @param string $key
	 * @param bool $multiple If true, returns an array of uploads for this key
	 * @param bool $skipErrors If true, uploads with errors will not be returned
	 *
	 * @return Upload|Upload[]
	 */
	public function getFile($key, $multiple = false, $skipErrors = true)
	{
		if (!isset($this->files[$key]['name']))
		{
			return ($multiple ? [] : null);
		}

		if (is_array($this->files[$key]['name']))
		{
			// multiple uploads
			$files = [];
			foreach (array_keys($this->files[$key]['name']) AS $idx)
			{
				$output[$idx] = [
					'name' => $this->files[$key]['name'][$idx],
					'type' => $this->files[$key]['type'][$idx],
					'size' => $this->files[$key]['size'][$idx],
					'tmp_name' => $this->files[$key]['tmp_name'][$idx],
					'error' => $this->files[$key]['error'][$idx],
				];
			}
		}
		else
		{
			// single upload
			$files = [$this->files[$key]];
		}

		$output = [];

		$imageI = 1;
		$imageBase = 'img-' . gmdate('Y-m-d-H-i-s') . '-';

		foreach ($files AS $idx => $file)
		{
			if ($file['error'] == UPLOAD_ERR_NO_FILE || ($skipErrors && $file['error']))
			{
				// didn't upload a file or has errors - just ignore
				continue;
			}

			// this handles files uploaded via JS that don't have a proper filename
			if ($file['name'] == 'blob' && preg_match('#^image/(pjpeg|jpeg|gif|png)$#', $file['type'], $match))
			{
				switch ($match[1])
				{
					case 'jpeg':
					case 'pjpeg':
						$type = 'jpg';
						break;

					default:
						$type = $match[1];
				}

				$file['name'] = $imageBase . $imageI . '.' . $type;
				$imageI++;
			}

			$output[$idx] = new Upload($file['tmp_name'], $file['name'], $file['error']);
		}

		if ($multiple)
		{
			return $output;
		}
		else
		{
			return reset($output);
		}
	}

	public function getCookie($key, $fallback = false)
	{
		return $this->getCookieRaw($this->cookiePrefix . $key, $fallback);
	}

	public function getCookies($prefixFiltered = true)
	{
		if (!$prefixFiltered)
		{
			return $this->cookie;
		}

		$output = [];
		$prefixLength = strlen($this->cookiePrefix);

		foreach ($this->cookie AS $cookie => $value)
		{
			if (substr($cookie, 0, $prefixLength) == $this->cookiePrefix)
			{
				$cookie = substr($cookie, $prefixLength);
				if (is_string($cookie) && strlen($cookie))
				{
					$output[$cookie] = $value;
				}
			}
		}

		return $output;
	}

	public function getCookieRaw($key, $fallback = false)
	{
		if (array_key_exists($key, $this->cookie))
		{
			return $this->cookie[$key];
		}
		else
		{
			return $fallback;
		}
	}

	public function getInputRaw($fallback = '')
	{
		$input = file_get_contents('php://input');
		return ($input ?: $fallback);
	}

	public function getIp($allowProxied = false)
	{
		if ($allowProxied && $ip = $this->getServer('HTTP_CLIENT_IP'))
		{
			list($ip) = explode(',', $ip);
			return $this->getFilteredIp($ip);
		}
		else if ($allowProxied && $ip = $this->getServer('HTTP_X_FORWARDED_FOR'))
		{
			list($ip) = explode(',', $ip);
			return $this->getFilteredIp($ip);
		}

		if ($this->_remoteIp === null)
		{
			$ip = $this->getTrustedRealIp($this->getServer('REMOTE_ADDR'));
			$this->_remoteIp = $this->getFilteredIp($ip);
		}

		return $this->_remoteIp;
	}

	protected function getTrustedRealIp($ip)
	{
		$via = $this->getServer('HTTP_VIA');
		if ($via && strpos(strtolower($via), 'chrome-compression-proxy'))
		{
			// may have Google Data Saver enabled
			$realIps = $this->getServer('HTTP_X_FORWARDED_FOR');
			if ($realIps)
			{
				$realIps = explode(',', $realIps);
				$realIp = end($realIps);
				$realIp = trim($realIp);

				if ($realIp === $ip || $this->ipMatchesRanges($ip, self::$googleIps))
				{
					// if the IP comes from a known Google IP, then we can trust that they put the client IP
					// in X-Forwarded-For. (They should have appended it to the end.)
					return $realIp;
				}
			}
		}

		$cfIp = $this->getServer('HTTP_CF_CONNECTING_IP');
		if ($cfIp && $cfIp !== $ip)
		{
			if ($this->ipMatchesRanges($ip, self::$cloudFlareIps))
			{
				// connection from known CloudFlare IP, real IP in their header
				return $cfIp;
			}
		}

		return $ip;
	}

	protected function ipMatchesRanges($ip, array $ranges)
	{
		$ip = \XF\Util\Ip::convertIpStringToBinary($ip);
		if ($ip === false)
		{
			return false;
		}

		$type = strlen($ip) == 4 ? 'v4' : 'v6';

		if (empty($ranges[$type]))
		{
			return false;
		}

		foreach ($ranges[$type] AS $range)
		{
			if (is_string($range))
			{
				$range = explode('/', $range);
			}

			$rangeIp = \XF\Util\Ip::convertIpStringToBinary($range[0]);
			$cidr = intval($range[1]);

			if (\XF\Util\Ip::ipMatchesCidrRange($ip, $rangeIp, $cidr))
			{
				return true;
			}
		}

		return false;
	}

	protected function getFilteredIp($ip)
	{
		$ip = trim($ip);

		if (preg_match('#:(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})$#', $ip, $match))
		{
			// embedded IPv4
			$long = ip2long($match[1]);
			if (!$long)
			{
				return $ip;
			}

			return $match[1];
		}

		return $ip;
	}

	public function getUserAgent()
	{
		return $this->getServer('HTTP_USER_AGENT');
	}

	public function getReferrer()
	{
		return $this->getServer('HTTP_REFERER');
	}

	public function getServer($key, $fallback = false)
	{
		if (array_key_exists($key, $this->server))
		{
			return $this->server[$key];
		}
		else
		{
			return $fallback;
		}
	}

	public function isGet()
	{
		return strtolower($this->getServer('REQUEST_METHOD')) === 'get';
	}

	public function isHead()
	{
		return strtolower($this->getServer('REQUEST_METHOD')) === 'head';
	}

	public function isPost()
	{
		return strtolower($this->getServer('REQUEST_METHOD')) === 'post';
	}

	public function isXhr()
	{
		return ($this->getServer('HTTP_X_REQUESTED_WITH') === 'XMLHttpRequest');
	}

	public function isSecure()
	{
		return (
			$this->getServer('REQUEST_SCHEME') === 'https'
			|| $this->getServer('HTTP_X_FORWARDED_PROTO') === 'https'
			|| $this->getServer('HTTPS') === 'on'
			|| $this->getServer('SERVER_PORT') == 443
		);
	}

	public function getProtocol()
	{
		return $this->isSecure() ? 'https' : 'http';
	}

	public function getBasePath()
	{
		if (isset($this->server['SCRIPT_NAME']))
		{
			$scriptName = $this->server['SCRIPT_NAME'];
			$lastSlash = strrpos($scriptName, '/');
			if ($lastSlash) // intentionally skipping for false and 0
			{
				return substr($scriptName, 0, $lastSlash);
			}
			else
			{
				return '/';
			}
		}
		else
		{
			return '/';
		}
	}

	public function getFullBasePath()
	{
		return $this->getHostUrl() . $this->getBasePath();
	}

	public function getExtendedUrl($requestUri = null)
	{
		$baseUrl = $this->getBaseUrl();
		$basePath = $this->getBasePath();

		if ($requestUri === null)
		{
			$requestUri = $this->getRequestUri();
		}

		if (strpos($requestUri, $baseUrl) === 0)
		{
			return strval(substr($requestUri, strlen($baseUrl)));
		}
		else if (strpos($requestUri, $basePath) === 0)
		{
			return strval(substr($requestUri, strlen($basePath)));
		}
		else
		{
			return $requestUri;
		}
	}

	public function getBaseUrl()
	{
		return $this->getServer('SCRIPT_NAME', '');
	}

	public function getRequestUri()
	{
		return $this->getServer('REQUEST_URI', '');
	}

	public function getFullRequestUri()
	{
		return $this->getHostUrl() . $this->getRequestUri();
	}

	public function getHost()
	{
		$host = $this->getServer('HTTP_HOST');
		if (!$host)
		{
			$host = $this->getServer('SERVER_NAME');
			$port = intval($this->getServer('SERVER_PORT'));
			if ($port && $port != 80 && $port != 443)
			{
				$host .= ":$port";
			}
		}

		return $host;
	}

	public function getHostUrl()
	{
		return $this->getProtocol() . '://' . $this->getHost();
	}

	public function getRoutePath()
	{
		$xfRoute = $this->filter('_xfRoute', 'str');
		if ($xfRoute)
		{
			return $xfRoute;
		}
		$routePath = ltrim($this->getExtendedUrl(), '/');
		return $this->getRoutePathInternal($routePath);
	}

	public function getRoutePathFromExtended($extended)
	{
		$routePath = ltrim($extended, '/');
		return $this->getRoutePathInternal($routePath);
	}

	public function getRoutePathFromUrl($url)
	{
		$url = $this->convertToAbsoluteUri($url);
		$url = str_replace($this->getHostUrl(), '', $url);

		$routePath = ltrim($this->getExtendedUrl($url), '/');

		return $this->getRoutePathInternal($routePath);
	}

	protected function getRoutePathInternal($routePath)
	{
		if (strlen($routePath) == 0)
		{
			return '';
		}

		if ($routePath[0] == '?')
		{
			$routePath = substr($routePath, 1);

			$nextArg = strpos($routePath, '&');
			if ($nextArg !== false)
			{
				$routePath = substr($routePath, 0, $nextArg);
			}

			if (strpos($routePath, '=') !== false)
			{
				return ''; // first bit has a "=" so it's named
			}
		}
		else
		{
			$queryStart = strpos($routePath, '?');
			if ($queryStart !== false)
			{
				$routePath = substr($routePath, 0, $queryStart);
			}
		}

		return strval($routePath);
	}

	public function convertToAbsoluteUri($uri, $fullBasePath = null)
	{
		if (!$fullBasePath)
		{
			$fullBasePath = $this->getFullBasePath();
		}

		$fullBasePath = rtrim($fullBasePath, '/');
		$baseParts = parse_url($fullBasePath);

		if ($uri == '.')
		{
			$uri = ''; // current directory
		}

		if (substr($uri, 0, 2) == '//')
		{
			return $baseParts['scheme'] . ':' . $uri;
		}
		else if (substr($uri, 0, 1) == '/')
		{
			return $baseParts['scheme'] . '://' . $baseParts['host']
				. (!empty($baseParts['port']) ? ":$baseParts[port]" : '')  . $uri;
		}
		else if (preg_match('#^[a-z0-9-]+://#i', $uri))
		{
			return $uri;
		}
		else
		{
			return $fullBasePath . '/' . $uri;
		}
	}
}