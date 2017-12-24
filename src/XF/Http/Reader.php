<?php

namespace XF\Http;

use XF\Util\Ip;

class Reader
{
	const ERROR_TIME = 1;
	const ERROR_SIZE = 2;
	const ERROR_CONNECTION = 3;

	/**
	 * @var \GuzzleHttp\Client
	 */
	protected $clientTrusted;

	/**
	 * @var \GuzzleHttp\Client
	 */
	protected $clientUntrusted;

	protected $untrustedAllowedSchemes = ['http', 'https'];
	protected $untrustedAllowedPorts = [80, 443];

	public function __construct(\GuzzleHttp\Client $clientTrusted, \GuzzleHttp\Client $clientUntrusted)
	{
		$this->clientTrusted = $clientTrusted;
		$this->clientUntrusted = $clientUntrusted;
	}

	public function get($url, array $limits = [], $saveTo = null, array $options = [], &$error = null)
	{
		return $this->_get($this->clientTrusted, $url, $limits, $saveTo, $options, $error);
	}

	public function getUntrusted($url, array $limits = [], $saveTo = null, array $options = [], &$error = null)
	{
		$options['allow_redirects'] = false;

		$requests = 0;

		do
		{
			$continue = false;
			$requests++;

			$url = preg_replace('/#.*$/', '', $url);
			if (!$this->isRequestableUntrustedUrl($url, $untrustedError))
			{
				$error = "The URL is not requestable ($untrustedError)";
				return null;
			}

			$response = $this->_get($this->clientUntrusted, $url, $limits, $saveTo, $options, $error);
			if (
				$response instanceof \GuzzleHttp\Message\Response
				&& $response->getStatusCode() >= 300
				&& $response->getStatusCode() < 400
				&& ($location = $response->getHeader('Location'))
			)
			{
				$location = \GuzzleHttp\Url::fromString($location);
				if (!$location->isAbsolute()) {
					$originalUrl = \GuzzleHttp\Url::fromString($url);
					$originalUrl->getQuery()->clear();
					$location = $originalUrl->combine($location);
				}
				$location = strval($location);

				if ($location != $url)
				{
					$url = $location;
					$continue = true;
				}
			}
		}
		while ($continue && $requests < 5);

		return $response;
	}

	public function isRequestableUntrustedUrl($url, &$error = null)
	{
		$parts = @parse_url($url);

		if (!$parts || empty($parts['scheme']) || empty($parts['host']))
		{
			$error = 'invalid';
			return false;
		}

		if (!in_array(strtolower($parts['scheme']), $this->untrustedAllowedSchemes))
		{
			$error = 'scheme';
			return false;
		}

		if (!empty($parts['port']) && !in_array($parts['port'], $this->untrustedAllowedPorts))
		{
			$error = 'port';
			return false;
		}

		if (!empty($parts['user']) || !empty($parts['pass']))
		{
			$error = 'userpass';
			return false;
		}

		if (strpos($parts['host'], '[') !== false)
		{
			$error = 'ipv6';
			return false;
		}

		if (preg_match('/^[0-9]+$/', $parts['host']))
		{
			$error = 'ipv4int';
			return false;
		}

		$hasValidIp = false;

		$ips = @gethostbynamel($parts['host']);
		if ($ips)
		{
			foreach ($ips AS $ip)
			{
				if ($this->isLocalIpv4($ip))
				{
					$error = "local: $ip";
					return false;
				}
				else
				{
					$hasValidIp = true;
				}
			}
		}

		if (function_exists('dns_get_record') && defined('DNS_AAAA'))
		{
			$hasIpv6 = defined('AF_INET6');
			if (!$hasIpv6 && function_exists('curl_version') && defined('CURL_VERSION_IPV6'))
			{
				$version = curl_version();
				if ($version['features'] & CURL_VERSION_IPV6)
				{
					$hasIpv6 = true;
				}
			}

			if ($hasIpv6)
			{
				$ipv6s = @dns_get_record($parts['host'], DNS_AAAA);
				if ($ipv6s)
				{
					foreach ($ipv6s AS $ipv6)
					{
						$ip = $ipv6['ipv6'];
						if ($this->isLocalIpv6($ip))
						{
							$error = "local: $ip";
							return false;
						}
						else
						{
							$hasValidIp = true;
						}
					}
				}
			}
		}

		if (!$hasValidIp)
		{
			$error = 'dns';
			return false;
		}

		return true;
	}

	protected function isLocalIpv4($ip)
	{
		return preg_match('#^(
			0\.|
			10\.|
			100\.(6[4-9]|[7-9][0-9]|1[01][0-9]|12[0-7])\.|
			127\.|
			169\.254\.|
			172\.(1[6-9]|2[0-9]|3[01])\.|
			192\.0\.0\.|
			192\.0\.2\.|
			192\.88\.99\.|
			192\.168\.|
			198\.1[89]\.|
			198\.51\.100\.|
			203\.0\.113\.|
			224\.|
			240\.|
			255\.255\.255\.255
		)#x', $ip);
	}

	protected function isLocalIpv6($ip)
	{
		$ip = Ip::convertIpStringToBinary($ip);

		$ranges = [
			'::' => 128,
			'::1' => 128,
			'::ffff:0:0' => 96,
			'100::' => 64,
			'64:ff9b::' => 96,
			'2001::' => 32,
			'2001:db8::' => 32,
			'2002::' => 16,
			'fc00::' => 7,
			'fe80::' => 10,
			'ff00::' => 8
		];
		foreach ($ranges AS $rangeIp => $cidr)
		{
			$rangeIp = Ip::convertIpStringToBinary($rangeIp);
			if (Ip::ipMatchesCidrRange($ip, $rangeIp, $cidr))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param \GuzzleHttp\Client $client
	 * @param string $url
	 * @param array $limits
	 * @param null|string $saveTo
	 * @param array $options
	 * @param mixed $error
	 *
	 * @return \GuzzleHttp\Message\ResponseInterface|null
	 */
	protected function _get(
		\GuzzleHttp\Client $client,
		$url, array $limits = [], $saveTo = null, array $options = [], &$error = null
	)
	{
		$limits = array_merge([
			'time' => -1,
			'bytes' => -1
		], $limits);
		$maxTime = intval($limits['time']);
		$maxSize = intval($limits['bytes']);

		$options = array_merge([
			'decode_content' => 'identity',
			'timeout' => $maxTime > -1 ? $maxTime + 1 : 30,
			'connect_timeout' => 3,
			'exceptions' => false
		], $options);

		if (!$saveTo)
		{
			$saveTo = 'php://temp';
		}

		if (is_string($saveTo))
		{
			$closeOnError = true;
			$saveTo = fopen($saveTo, 'w+');
		}
		else
		{
			$closeOnError = false;
		}

		$saveTo = \GuzzleHttp\Stream\Stream::factory($saveTo);
		$saveTo = new Stream($saveTo, $maxSize, $maxTime);

		$options['save_to'] = $saveTo;

		try
		{
			$response = $client->get($url, $options);
		}
		catch (\GuzzleHttp\Exception\RequestException $e)
		{
			if ($saveTo->hasError($errorCode))
			{
				$error = $this->getErrorMessage($errorCode);
			}
			else
			{
				$error = $e->getMessage();
			}

			if ($closeOnError)
			{
				$saveTo->close();
			}

			return null;
		}

		if ($saveTo->hasError($errorCode))
		{
			$error = $this->getErrorMessage($errorCode);

			if ($closeOnError)
			{
				$saveTo->close();
			}

			return null;
		}

		return $response;
	}

	public function getErrorMessage($code)
	{
		switch ($code)
		{
			case self::ERROR_SIZE:
				return \XF::phraseDeferred('file_is_too_large');

			case self::ERROR_TIME:
				return \XF::phrase('server_was_too_slow');

			default:
				return \XF::phraseDeferred('unknown');
		}
	}
}