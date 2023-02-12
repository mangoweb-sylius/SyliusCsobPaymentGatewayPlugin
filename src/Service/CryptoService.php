<?php

declare(strict_types=1);

namespace MangoSylius\CsobPaymentGatewayPlugin\Service;

use function base64_encode;
use const OPENSSL_ALGO_SHA256;
use function openssl_pkey_get_private;
use SlevomatCsobGateway\Crypto\PrivateKeyFileException;
use SlevomatCsobGateway\Crypto\SignatureDataFormatter;
use SlevomatCsobGateway\Crypto\SigningFailedException;

class CryptoService extends \SlevomatCsobGateway\Crypto\CryptoService
{
	public const HASH_METHOD = OPENSSL_ALGO_SHA256;

	/** @var string */
	private $privateKeyFile;

	/** @var string|null */
	private $privateKeyPassword;

	public function __construct(
		string $privateKeyFile,
		string $bankPublicKeyFile,
		string $privateKeyPassword = ''
	) {
		parent::__construct($privateKeyFile, $bankPublicKeyFile, $privateKeyPassword);

		$this->privateKeyFile = $privateKeyFile;
		$this->privateKeyPassword = $privateKeyPassword;
	}

	/**
	 * @param mixed[] $data
	 *
	 * @throws PrivateKeyFileException
	 * @throws SigningFailedException
	 */
	public function signData(array $data, SignatureDataFormatter $signatureDataFormatter): string
	{
		$message = $signatureDataFormatter->formatDataForSignature($data);

		$privateKeyId = openssl_pkey_get_private($this->privateKeyFile, (string) $this->privateKeyPassword);
		if ($privateKeyId === false) {
			throw new PrivateKeyFileException($this->privateKeyFile);
		}

		$ok = openssl_sign($message, $signature, $privateKeyId, self::HASH_METHOD);
		if (!$ok) {
			throw new SigningFailedException($data);
		}

		$signature = base64_encode($signature);
		openssl_free_key($privateKeyId);

		return $signature;
	}
}
