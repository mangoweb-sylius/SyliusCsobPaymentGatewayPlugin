<?php

declare(strict_types=1);

namespace MangoSylius\CsobPaymentGatewayPlugin\Api;

use MangoSylius\CsobPaymentGatewayPlugin\Service\CryptoService;
use SlevomatCsobGateway\Api\ApiClient;
use SlevomatCsobGateway\Api\Driver\CurlDriver;
use SlevomatCsobGateway\Api\HttpMethod;
use SlevomatCsobGateway\Api\InvalidSignatureException;
use SlevomatCsobGateway\Call\PaymentStatus;
use SlevomatCsobGateway\Call\PayMethod;
use SlevomatCsobGateway\Call\PayOperation;
use SlevomatCsobGateway\Cart;
use SlevomatCsobGateway\Currency;
use SlevomatCsobGateway\Language;
use SlevomatCsobGateway\RequestFactory;
use Sylius\Component\Core\Context\ShopperContextInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class CsobApi implements CsobApiInterface
{
	/** @var ShopperContextInterface */
	protected $shopperContext;

	/** @var TranslatorInterface */
	protected $translator;

	public function __construct(
		TranslatorInterface $translator,
		ShopperContextInterface $shopperContext
	) {
		$this->translator = $translator;
		$this->shopperContext = $shopperContext;
	}

	private function createApi(bool $sandbox, string $keyPrivate): ApiClient
	{
		$serverCert = $sandbox
			? __DIR__ . '/../Resources/keys/serverKeys/sandbox/mips_platebnibrana.csob.cz.pub'
			: __DIR__ . '/../Resources/keys/serverKeys/prod/mips_platebnibrana.csob.cz.pub';

		$apiEndpoint = $sandbox
			? 'https://iapi.iplatebnibrana.csob.cz/api/v1.8'
			: 'https://api.platebnibrana.csob.cz/api/v1.8';

		return new ApiClient(
			new CurlDriver(),
			new CryptoService($keyPrivate, $serverCert),
			$apiEndpoint
		);
	}

	public function create(array $order, string $merchantId, bool $sandbox, string $keyPrivate): array
	{
		$apiClient = $this->createAPI($sandbox, $keyPrivate);
		$requestFactory = new RequestFactory($merchantId);

		$cart = new Cart(Currency::get($order['currency']));
		$cart->addItem(
			$this->translator->trans('mango-sylius.csob_plugin.itemText'),
			1,
			$order['amount']
		);

		$locale = $localeCode = $this->shopperContext->getLocaleCode();
		if ($locale === 'cs') {
			$language = Language::get(Language::CZ);
		} else {
			$language = Language::get(strtoupper($locale));
		}
		$clientId = isset($order['clientId']) ? (string) ($order['clientId']) : null;

		$paymentResponse = $requestFactory->createInitPayment(
			$order['orderNumber'],
			PayOperation::get(PayOperation::PAYMENT),
			PayMethod::get(PayMethod::CARD),
			true,
			$order['returnUrl'],
			HttpMethod::get(HttpMethod::POST),
			$cart,
			$order['description'],
			$clientId,
			$language,
		)->send($apiClient);

		$payId = $paymentResponse->getPayId();
		$processPaymentResponse = $requestFactory->createProcessPayment($payId)->send($apiClient);

		return [
			'orderId' => $order['orderNumber'],
			'gatewayLocationUrl' => $processPaymentResponse->getGatewayLocationUrl(),
			'payId' => $payId,
		];
	}

	public function retrieve(string $merchantId, bool $sandbox, string $keyPrivate, string $externalPaymentId): string
	{
		$apiClient = $this->createAPI($sandbox, $keyPrivate);
		$requestFactory = new RequestFactory($merchantId);

		try {
			$paymentResponse = $requestFactory->createReceivePaymentRequest()->send($apiClient, $_POST);
			if ($paymentResponse->getPaymentStatus() !== null
				&& (
					$paymentResponse->getPaymentStatus()->equalsValue(PaymentStatus::S7_AWAITING_SETTLEMENT)
					|| $paymentResponse->getPaymentStatus()->equalsValue(PaymentStatus::S8_CHARGED)
				)) {
				return CsobApiInterface::PAID;
			}
		} catch (InvalidSignatureException $e) {
			$status = $requestFactory->createPaymentStatus($externalPaymentId)->send($apiClient)->getPaymentStatus();
			if ($status !== null
				&& (
					$status->equalsValue(PaymentStatus::S7_AWAITING_SETTLEMENT)
					|| $status->equalsValue(PaymentStatus::S8_CHARGED
				))) {
				return CsobApiInterface::PAID;
			}
		}

		return CsobApiInterface::CANCELED;
	}
}
