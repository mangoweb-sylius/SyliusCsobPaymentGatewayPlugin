<?php

declare(strict_types=1);

namespace MangoSylius\CsobPaymentGatewayPlugin\Action;

use MangoSylius\CsobPaymentGatewayPlugin\Api\CsobApiInterface;
use MangoSylius\CsobPaymentGatewayPlugin\SetCsob;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Exception\UnsupportedApiException;
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Security\TokenInterface;

class CsobAction implements ApiAwareInterface, ActionInterface
{
	/** @var CsobApiInterface */
	protected $csobApi;

	/** @var array<mixed> */
	private $api = [];

	/**
	 * @param mixed $api
	 */
	public function setApi($api): void
	{
		if (!is_array($api)) {
			throw new UnsupportedApiException('Not supported.');
		}

		$this->api = $api;
	}

	public function __construct(CsobApiInterface $csobApi)
	{
		$this->csobApi = $csobApi;
	}

	/**
	 * @param mixed $request
	 */
	public function execute($request): void
	{
		RequestNotSupportedException::assertSupports($this, $request);
		$model = ArrayObject::ensureArrayObject($request->getModel());

		$sandbox = (bool) $this->api['sandbox'];
		$merchantId = (string) $this->api['merchantId'];
		$keyPrivate = (string) $this->api['keyPrivate'];

		// Not new order
		if ($model['orderId'] !== null && $model['externalPaymentId'] !== null) {
			$status = $this->csobApi->retrieve($merchantId, $sandbox, $keyPrivate, $model['externalPaymentId']);
			$model['csobStatus'] = $status;

			return;
		}

		// New order
		/** @var TokenInterface $token */
		$token = $request->getToken();
		$order = $this->prepareOrder($token, $model);
		$response = $this->csobApi->create($order, $merchantId, $sandbox, $keyPrivate);

		if ($response) {
			$model['orderId'] = $response['orderId'];
			$model['externalPaymentId'] = $response['payId'];
			$request->setModel($model);

			throw new HttpRedirect($response['gatewayLocationUrl']);
		}

		throw new \RuntimeException();
	}

	/**
	 * @param mixed $request
	 */
	public function supports($request): bool
	{
		return
			$request instanceof SetCsob &&
			$request->getModel() instanceof \ArrayObject;
	}

	/**
	 * @param mixed $model
	 *
	 * @return array<mixed>
	 */
	private function prepareOrder(TokenInterface $token, $model): array
	{
		$order = [];
		$order['currency'] = $model['currencyCode'];
		$order['description'] = $model['description'];
		$order['amount'] = $model['totalAmount'];
		$order['orderNumber'] = $model['extOrderId'];
		$order['clientId'] = $model['clientId'];
		$order['items'] = $this->resolveProducts($model);
		$order['returnUrl'] = $token->getTargetUrl();

		return $order;
	}

	/**
	 * @return array<mixed>
	 */
	private function resolveProducts(ArrayObject $model): array
	{
		$items = $model->get('items');

		if (null !== $items || (is_array($items) && count($model['items']) === 0)) {
			return [
				[
					'name' => $model['description'],
					'amount' => $model['totalAmount'],
				],
			];
		}

		return [];
	}
}
