<?php

namespace XF\ConnectedAccount\Http;

use OAuth\Common\Http\Client\AbstractClient;
use OAuth\Common\Http\Uri\UriInterface;

class Client extends AbstractClient
{
	/**
	 * Any implementing HTTP providers should send a request to the provided endpoint with the parameters.
	 * They should return, in string form, the response body and throw an exception on error.
	 *
	 * @param UriInterface $endpoint
	 * @param mixed        $requestBody
	 * @param array        $extraHeaders
	 * @param string       $method
	 *
	 * @return string
	 *
	 * @throws HttpResponseException
	 * @throws \InvalidArgumentException
	 */
	public function retrieveResponse(UriInterface $endpoint, $requestBody, array $extraHeaders = [], $method = 'POST')
	{
		$method = strtoupper($method);

		if ($method === 'GET' && !empty($requestBody))
		{
			throw new \InvalidArgumentException('No body expected for "GET" request.');
		}

		if (!isset($extraHeaders['Content-Type']) && $method === 'POST' && is_array($requestBody))
		{
			$extraHeaders['Content-Type'] = 'application/x-www-form-urlencoded';
		}

		$extraHeaders['Host'] = $endpoint->getHost();
		$extraHeaders['Connection'] = 'close';
		$extraHeaders['User-Agent'] = $this->userAgent;

		$client = \XF::app()->http()->client();

		$requestBodyString = '';
		if ($method === 'POST' || $method === 'PUT')
		{
			if ($requestBody && is_array($requestBody))
			{
				$requestBodyString = http_build_query($requestBody, '', '&');
			}
			else
			{
				$requestBodyString = $requestBody;
			}
		}
		$extraHeaders['Content-length'] = ($requestBodyString && is_string($requestBodyString)) ? strlen($requestBodyString) : 0;

		$request = $client->createRequest($method, $endpoint->getAbsoluteUri(), [
			'allow_redirects' => [
				'max' => $this->maxRedirects
			],
			'body' => $requestBodyString,
			'headers' => $extraHeaders,
			'timeout' => $this->timeout,
			'exceptions' => false
		]);

		$response = $client->send($request);

		$body = $response->getBody();
		$content = $body ? $body->getContents() : '';

		$code = $response->getStatusCode();
		if ($code >= 400)
		{
			$exception = new HttpResponseException("Failed to request resource. HTTP Code: $code", $code);
			$exception->setResponseContent($content);
			throw $exception;
		}

		if (!$body)
		{
			$exception = new HttpResponseException("Failed to request resource. No body.", $code);
			$exception->setResponseContent($content);
			throw $exception;
		}

		return $content;
	}
}