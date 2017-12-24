<?php

namespace XF\Payment;

use XF\Entity\PaymentProfile;
use XF\Entity\PurchaseRequest;
use XF\Mvc\Controller;
use XF\Purchasable\Purchase;

class Stripe extends AbstractProvider
{
	public function getTitle()
	{
		return 'Stripe';
	}

	public function getApiEndpoint()
	{
		return 'https://api.stripe.com';
	}

	public function initiatePayment(Controller $controller, PurchaseRequest $purchaseRequest, Purchase $purchase)
	{
		$paymentProfile = $purchase->paymentProfile;

		if (\XF::config('enableLivePayments'))
		{
			$publishableKey = $paymentProfile->options['live_publishable_key'];
		}
		else
		{
			$publishableKey = $paymentProfile->options['test_publishable_key'];
		}

		$viewParams = [
			'purchaseRequest' => $purchaseRequest,
			'paymentProfile' => $paymentProfile,
			'purchaser' => $purchase->purchaser,
			'purchase' => $purchase,
			'purchasableTypeId' => $purchase->purchasableTypeId,
			'purchasableId' => $purchase->purchasableId,
			'publishableKey' => $publishableKey,
			'cost' => $this->prepareCost($purchase->cost, $purchase->currency)
		];
		return $controller->view('XF:Purchase\StripeInitiate', 'payment_initiate_stripe', $viewParams);
	}

	public function processPayment(Controller $controller, PurchaseRequest $purchaseRequest, PaymentProfile $paymentProfile, Purchase $purchase)
	{
		$response = $controller->filter('response', 'array');

		if (empty($response['id']))
		{
			if (!empty($response['error']['message']))
			{
				$error = $response['error']['message'];
			}
			else
			{
				$error = \XF::phrase('error_occurred_while_creating_stripe_card_token');
			}
			throw $controller->exception($controller->error($error));
		}

		$transactionId = '';

		$plan = [];
		$charge = [];

		$cardToken = $response['id'];

		if (\XF::config('enableLivePayments'))
		{
			$secretKey = $paymentProfile->options['live_secret_key'];
		}
		else
		{
			$secretKey = $paymentProfile->options['test_secret_key'];
		}

		$currency = $purchase->currency;
		$cost = $this->prepareCost($purchase->cost, $currency);

		try
		{
			$client = \XF::app()->http()->client();

			$customerData = [
				'email' => $purchase->purchaser->email,
				'metadata' => [
					'request_key' => $purchaseRequest->request_key
				],
				'description' => $purchase->title,
				'source' => $cardToken
			];

			if ($purchase->recurring)
			{
				$planId = $purchase->purchasableTypeId . '_' . md5($currency . $cost . $purchase->lengthAmount . $purchase->lengthUnit);

				// Get the existing plan if one exists
				$planResponse = $client->get($this->getApiEndpoint() . '/v1/plans/' . $planId, [
					'auth' => [$secretKey, ''],
					'exceptions' => false
				]);
				$plan = $planResponse->json();
				if (!isset($plan['id']))
				{
					// Create a new plan
					$createPlanResponse = $client->post($this->getApiEndpoint() . '/v1/plans', [
						'auth' => [$secretKey, ''],
						'body' => [
							'id' => $planId,
							'amount' => $cost,
							'currency' => $currency,
							'name' => $purchase->purchasableTitle,
							'interval' => $purchase->lengthUnit,
							'interval_count' => $purchase->lengthAmount,
							'statement_descriptor' => utf8_substr(str_replace(['<', '>', '"', '\''], '', $purchase->purchasableTitle), 0, 22)
						]
					]);
					$plan = $createPlanResponse->json();
					if (!isset($plan['id']))
					{
						throw $controller->exception($controller->error(\XF::phrase('could_not_create_new_stripe_plan')));
					}
				}
				$customerData['plan'] = $plan['id'];
			}

			$customerCreateResponse = $client->post($this->getApiEndpoint() . '/v1/customers', [
				'auth' => [$secretKey, ''],
				'body' => $customerData
			]);
			$customer = $customerCreateResponse->json();
			$customerId = $customer['id'];

			$paymentRepo = \XF::repository('XF:Payment');

			if (isset($customer['subscriptions']['data'][0]['id']))
			{
				$paymentRepo->logCallback(
					$purchaseRequest->request_key,
					$this->providerId,
					$transactionId,
					'info',
					'Subscription created', [],
					$customer['subscriptions']['data'][0]['id']
				);
			}

			if (!$purchase->recurring && $customer)
			{
				$chargeResponse = $client->post($this->getApiEndpoint() . '/v1/charges', [
					'auth' => [$secretKey, ''],
					'body' => [
						'amount' => $cost,
						'currency' => $currency,
						'customer' => $customerId,
						'description' => $purchase->title
					]
				]);
				$charge = $chargeResponse->json();
				$transactionId = $charge['id'];
			}
		}
		catch (\GuzzleHttp\Exception\ClientException $e)
		{
			$error = $e->getResponse()->json();
			$message = isset($error['error']['message']) ? $error['error']['message'] : '';

			throw $controller->exception($controller->noPermission($message));
		}
		catch (\GuzzleHttp\Exception\RequestException $e)
		{
			\XF::logException($e, false, "Stripe error: ");

			throw $controller->exception($controller->error(\XF::phrase('something_went_wrong_please_try_again')));
		}

		$paymentRepo->logCallback(
			$purchaseRequest->request_key,
			$this->providerId,
			$transactionId,
			'info',
			'Customer and plan/charge created',
			[
				'plan' => $plan,
				'customer' => $customer,
				'charge' => $charge
			],
			$customerId
		);

		return $controller->redirect($purchase->returnUrl);
	}

	public function renderCancellation(\XF\Entity\UserUpgradeActive $active)
	{
		$data = [
			'active' => $active,
			'purchaseRequest' => $active->PurchaseRequest
		];
		return \XF::app()->templater()->renderTemplate('public:payment_cancel_recurring_braintree', $data);
	}

	public function processCancellation(Controller $controller, PurchaseRequest $purchaseRequest, PaymentProfile $paymentProfile)
	{
		$logFinder = \XF::finder('XF:PaymentProviderLog')
			->where('purchase_request_key', $purchaseRequest->request_key)
			->where('provider_id', $this->providerId)
			->order('log_date', 'desc');

		$logs = $logFinder->fetch();

		$subscriberId = null;
		$customerId = null;
		foreach ($logs AS $log)
		{
			if ($log->subscriber_id && strpos($log->subscriber_id, 'sub_') === 0)
			{
				$subscriberId = $log->subscriber_id;
			}

			if ($log->subscriber_id && strpos($log->subscriber_id, 'cus_') === 0)
			{
				$customerId = $log->subscriber_id;
			}
		}

		if (!$subscriberId || !$customerId)
		{
			return $controller->error('Could not find a subscriber ID or customer ID for this purchase request.');
		}

		if (\XF::config('enableLivePayments'))
		{
			$secretKey = $paymentProfile->options['live_secret_key'];
		}
		else
		{
			$secretKey = $paymentProfile->options['test_secret_key'];
		}

		try
		{
			$client = \XF::app()->http()->client();
			$response = $client->delete($this->getApiEndpoint() . '/v1/customers/' . $customerId . '/subscriptions/' . $subscriberId, [
				'auth' => [$secretKey, '']
			]);
			$subscription = $response->json();
			if ($subscription['status'] != 'canceled')
			{
				throw $controller->exception($controller->error(
					\XF::phrase('this_subscription_cannot_be_cancelled_maybe_already_cancelled')
				));
			}
		}
		catch (\GuzzleHttp\Exception\RequestException $e)
		{
			throw $controller->exception($controller->error(
				\XF::phrase('this_subscription_cannot_be_cancelled_maybe_already_cancelled')
			));
		}

		return $controller->redirect(
			$controller->getDynamicRedirect(),
			\XF::phrase('stripe_subscription_cancelled_successfully')
		);
	}

	public function setupCallback(\XF\Http\Request $request)
	{
		$state = new CallbackState();

		$inputRaw = $request->getInputRaw();
		$state->inputRaw = $inputRaw;
		$state->signature = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? $_SERVER['HTTP_STRIPE_SIGNATURE'] : null;

		$input = @json_decode($inputRaw, true);
		$filtered = \XF::app()->inputFilterer()->filterArray($input ?: [], [
			'data' => 'array',
			'id' => 'str',
			'type' => 'str'
		]);

		$event = $filtered['data'];

		$state->transactionId = $filtered['id'];
		$state->eventType = $filtered['type'];

		$state->event = isset($event['object']) ? $event['object'] : [];

		if (isset($state->event['object']) && $state->event['object'] == 'subscription')
		{
			$state->subscriberId = $state->event['id'];
		}
		else
		{
			$state->subscriberId = isset($state->event['customer']) ? $state->event['customer'] : null;
		}
		
		return $state;
	}

	public function validateCallback(CallbackState $state)
	{
		if (!$state->signature)
		{
			$state->logType = 'error';
			$state->logMessage = 'Webhook received from Stripe does not contain a Stripe signature.';
			$state->httpCode = 500;

			return false;
		}

		if (!empty($state->paymentProfile->options['signing_secret']))
		{
			$secret = $state->paymentProfile->options['signing_secret'];

			$timestamp = null;
			$signature = null;

			$parts = explode(',', $state->signature);
			foreach ($parts AS $part)
			{
				list($key, $value) = explode('=', $part, 2);
				if ($key == 't')
				{
					$timestamp = $value;
				}
				else if ($key == 'v1')
				{
					$signature = $value;
				}
			}

			if (!$timestamp || !$signature)
			{
				$state->logType = 'error';
				$state->logMessage = 'Webhook received from Stripe could not be verified as being valid. Unexpected data in timestamp or signature.';
				$state->httpCode = 500;

				return false;
			}

			if (\XF::$time - $timestamp > 300)
			{
				$state->logType = 'error';
				$state->logMessage = 'Webhook received from Stripe could not be verified as being valid. Timestamp tolerance exceeded.';
				$state->httpCode = 500;

				return false;
			}

			$signedPayload = $timestamp . '.' . $state->inputRaw;
			$computed = hash_hmac('sha256', $signedPayload, $secret);

			if ($computed !== $signature)
			{
				$state->logType = 'error';
				$state->logMessage = 'Webhook received from Stripe could not be verified as being valid. Computed signature does not match received signature.';
				$state->httpCode = 500;

				return false;
			}
		}

		$purchaseRequest = null;
		if (!$state->requestKey && !empty($state->event['id']))
		{
			$chargeId = isset($state->event['charge']) ? $state->event['charge'] : $state->event['id'];

			if ($chargeId && strpos($chargeId, 'ch_') === 0)
			{
				$purchaseRequest = \XF::em()->findOne('XF:PurchaseRequest', [
					'provider_id' => $this->providerId,
					'provider_metadata' => $chargeId
				]);
				if (!$purchaseRequest)
				{
					$providerLog = \XF::em()->findOne('XF:PaymentProviderLog', [
						'provider_id' => $this->providerId,
						'transaction_id' => $chargeId
					]);
					if ($providerLog)
					{
						$state->requestKey = $providerLog->purchase_request_key;
					}
				}
			}
			if (!$purchaseRequest && $state->subscriberId)
			{
				$providerLog = \XF::em()->findOne('XF:PaymentProviderLog', [
					'provider_id' => $this->providerId,
					'subscriber_id' => $state->subscriberId
				]);
				if ($providerLog)
				{
					$state->requestKey = $providerLog->purchase_request_key;
				}
			}
			if ($purchaseRequest)
			{
				$state->purchaseRequest = $purchaseRequest;
				$state->requestKey = $purchaseRequest->request_key;
			}
		}

		if (!$state->eventType || !$state->event || !$state->requestKey)
		{
			$state->logType = 'error';
			$state->logMessage = 'Event data received from Stripe does not contain the expected values.';
			if (!$state->requestKey)
			{
				$state->httpCode = 200; // Not likely to recover from this error so send a successful response.
			}
			return false;
		}

		$paymentProfile = $state->getPaymentProfile();
		$purchaseRequest = $state->getPurchaseRequest();

		if (!$paymentProfile || !$purchaseRequest)
		{
			$state->logType = 'error';
			$state->logMessage = 'Invalid purchase request or payment profile.';
			return false;
		}

		if (\XF::config('enableLivePayments'))
		{
			$secretKey = $paymentProfile->options['live_secret_key'];
		}
		else
		{
			$secretKey = $paymentProfile->options['test_secret_key'];
		}

		try
		{
			$client = \XF::app()->http()->client();
			$response = $client->get($this->getApiEndpoint() . '/v1/events/' . $state->transactionId, [
				'auth' => [$secretKey, '']
			]);
			$retrievedEvent = $response->json();
		}
		catch (\GuzzleHttp\Exception\RequestException $e)
		{
			$state->logType = 'error';
			$state->logMessage = 'Connection to Stripe failed: ' . $e->getMessage();
			return false;
		}

		if (isset($retrievedEvent['data']['object']))
		{
			$state->event = $retrievedEvent['data']['object'];
			return true;
		}
		else
		{
			$state->logType = 'error';
			$state->logMessage = 'Event received from Stripe does not contain the expected object.';
			return false;
		}
	}

	public function validateTransaction(CallbackState $state)
	{
		if (!$state->transactionId)
		{
			$state->logType = 'info';
			$state->logMessage = 'No transaction ID. No action to take.';
			return false;
		}
		return parent::validateTransaction($state);
	}

	public function validateCost(CallbackState $state)
	{
		$purchaseRequest = $state->getPurchaseRequest();

		$currency = $purchaseRequest->cost_currency;
		$cost = $this->prepareCost($purchaseRequest->cost_amount, $currency);

		switch ($state->eventType)
		{
			case 'charge.succeeded':
				$costValidated = (
					$state->event['amount'] === $cost
					&& strtoupper($state->event['currency']) === $currency
				);

				if (!$costValidated)
				{
					$state->logType = 'error';
					$state->logMessage = 'Invalid cost amount';
					return false;
				}
				break;
		}

		return true;
	}

	public function setProviderMetadata(CallbackState $state)
	{
		// If there is a subsequent dispute, we'll receive this without any of the charge metadata (which
		// includes the request key) so we need to log some further data we can look up later in this case.
		if ($state->eventType == 'charge.succeeded')
		{
			$purchaseRequest = $state->getPurchaseRequest();
			$purchaseRequest->provider_metadata = $state->event['id'];
			$purchaseRequest->save();
		}
	}

	public function getPaymentResult(CallbackState $state)
	{
		switch ($state->eventType)
		{
			case 'charge.succeeded':
				$state->paymentResult = CallbackState::PAYMENT_RECEIVED;
				break;

			case 'charge.refunded':
			case 'charge.dispute.funds_withdrawn':
				$state->paymentResult = CallbackState::PAYMENT_REVERSED;
				break;

			case 'charge.dispute.funds_reinstated':
				$state->paymentResult = CallbackState::PAYMENT_REINSTATED;
				break;
		}
	}

	public function prepareLogData(CallbackState $state)
	{
		$state->logDetails = $state->event;
		$state->logDetails['eventType'] = $state->eventType;
	}

	protected $supportedCurrencies = [
		'AED', 'AFN', 'ALL', 'AMD', 'AOA',
		'ARS', 'AUD', 'AWG', 'AZN', 'BAM',
		'BBD', 'BDT', 'BGN', 'BIF', 'BMD',
		'BND', 'BOB', 'BRL', 'BWP', 'BZD',
		'CAD', 'CDF', 'CHF', 'CLP', 'CNY',
		'COP', 'CRC', 'CVE', 'CZK', 'DJF',
		'DKK', 'DOP', 'DZD', 'EGP', 'ETB',
		'EUR', 'GBP', 'GEL', 'GNF', 'GTQ',
		'GYD', 'HKD', 'HNL', 'HRK', 'HUF',
		'IDR', 'ILS', 'INR', 'ISK', 'JMD',
		'JPY', 'KES', 'KHR', 'KMF', 'KRW',
		'KZT', 'LBP', 'LKR', 'LRD', 'MAD',
		'MDL', 'MGA', 'MKD', 'MOP', 'MUR',
		'MXN', 'MYR', 'MZN', 'NAD', 'NGN',
		'NIO', 'NOK', 'NPR', 'NZD', 'PAB',
		'PEN', 'PHP', 'PKR', 'PLN', 'PYG',
		'QAR', 'RON', 'RSD', 'RUB', 'RWF',
		'SAR', 'SEK', 'SGD', 'SOS', 'STD',
		'THB', 'TOP', 'TRY', 'TTD', 'TWD',
		'TZS', 'UAH', 'UGX', 'USD', 'UYU',
		'UZS', 'VND', 'XAF', 'XOF', 'ZAR'
	];

	/**
	 * List of zero-decimal currencies as defined by Stripe's documentation. If we're dealing with one of these,
	 * this is already the smallest currency unit, and can be passed as-is. Otherwise convert it.
	 *
	 * @var array
	 */
	protected $zeroDecimalCurrencies = [
		'BIF', 'CLP', 'DJF', 'GNF', 'JPY',
		'KMF', 'KRW', 'MGA', 'PYG', 'RWF',
		'VND', 'VUV', 'XAF', 'XOF', 'XPF'
	];

	/**
	 * Given a cost and a currency, this will return the cost as an integer converted to the smallest currency unit.
	 *
	 * @param $cost
	 * @param $currency
	 *
	 * @return int
	 */
	protected function prepareCost($cost, $currency)
	{
		if (!in_array($currency, $this->zeroDecimalCurrencies))
		{
			$cost *= 100;
		}
		return intval($cost);
	}

	public function verifyCurrency(PaymentProfile $paymentProfile, $currencyCode)
	{
		return (in_array($currencyCode, $this->supportedCurrencies));
	}
}