<?php
namespace HikashopStorelinkr;

/**
 * An error to answer StoreLinkr with. Its message goes into the JSON body the
 * connector reads (never into HTML), and its code is the HTTP status.
 */
final class ApiError extends \RuntimeException {

	/** The error of a request: a message for StoreLinkr and an HTTP status. */
	public static function because($message, $status = 400) {
		return new self((string) $message, (int) $status);
	}

	/**
	 * Stop the request with an error for StoreLinkr. Its message goes to the
	 * JSON body only, never to a page, which is why it is built here and thrown
	 * as a whole.
	 */
	public static function raise($message, $status = 400) {
		$error = self::because($message, $status);
		throw $error;
	}

	/** Missing or wrong credentials: answered as the WooCommerce connector does. */
	public static function unauthorized() {
		return new self('UNAUTHORIZED', 401);
	}

	/** The JSON body of this error, in the WooCommerce connector's shapes. */
	public function body() {
		if ($this->getMessage() === 'UNAUTHORIZED') {
			return array(
				'code'    => 'rest_forbidden',
				'message' => 'You do not have permissions to view this data.',
				'data'    => array( 'status' => 401 ),
			);
		}
		return array(
			'code'    => $this->status(),
			'message' => $this->getMessage(),
			'data'    => array(
				'status'  => 'failed',
				'message' => $this->getMessage(),
			),
		);
	}

	/** The HTTP status to answer with. */
	public function status() {
		$code = (int) $this->getCode();
		return ( $code >= 400 && $code < 600 ) ? $code : 400;
	}
}
