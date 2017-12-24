<?php

namespace XF\SubContainer;

class Http extends AbstractSubContainer
{
	public function initialize()
	{
		$container = $this->container;

		$container['client'] = function($c)
		{
			return $this->createClient();
		};
		$container['clientUntrusted'] = function($c)
		{
			$client = $this->createClient();
			$client->setDefaultOption('allow_redirects', false);

			$config = $this->app->config();
			if ($config['http']['proxy'])
			{
				$client->setDefaultOption('proxy', $config['http']['proxy']);
			}

			$this->app->fire('http_client_config_untrusted', [&$client]);

			return $client;
		};

		$container['reader'] = function($c)
		{
			return new \XF\Http\Reader($c['client'], $c['clientUntrusted']);
		};
	}

	protected function applyDefaultClientConfig(\GuzzleHttp\Client $client)
	{
		$config = $this->app->config();
		if ($config['http']['sslVerify'] === null)
		{
			$bundleFileName = 'ca-bundle.crt';

			if (extension_loaded('curl')) // this should always be true...
			{
				$version = curl_version();
				if (preg_match('#openssl/(0|1\.0\.[01])#i', $version['ssl_version']))
				{
					// For OpenSSL < 1.0.2, we need to use a bundle that includes the Equifax cert as it will
					// always check if it knows the last certificate in the path. Google cross signs their certificates
					// with a known cert and the now-untrusted Equifax cert. See this for more details:
					// https://serverfault.com/questions/841036/openssl-unable-to-get-local-issuer-certificate-for-accounts-google-com
					$bundleFileName = 'ca-bundle-legacy-openssl.crt';
				}
			}

			$verify = \XF::getSourceDirectory() . "/XF/Http/" . $bundleFileName;
		}
		else
		{
			$verify = $config['http']['sslVerify'];
		}
		$client->setDefaultOption('verify', $verify);

		$options = $this->parent['options'];
		$client->setDefaultOption('headers', ['User-Agent' => 'XenForo/2.x (' . $options->boardUrl . ')']);

		$this->app->fire('http_client_config', [&$client]);
	}

	/**
	 * @param array $options
	 *
	 * @return \GuzzleHttp\Client
	 */
	public function createClient(array $options = [])
	{
		$client = new \GuzzleHttp\Client($options);
		$this->applyDefaultClientConfig($client);

		return $client;
	}

	/**
	 * @return \GuzzleHttp\Client
	 */
	public function client()
	{
		return $this->container['client'];
	}

	/**
	 * @return \XF\Http\Reader
	 */
	public function reader()
	{
		return $this->container['reader'];
	}
}