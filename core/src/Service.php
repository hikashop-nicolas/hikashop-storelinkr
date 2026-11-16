<?php
namespace HikashopStorelinkr;

/**
 * Base of the services: the platform, the settings, a warnings buffer
 * (returned to StoreLinkr like the WooCommerce connector does), and helpers
 * for the database and the default currency.
 */
abstract class Service {
	/** @var Platform */
	protected $platform;
	/** @var Settings */
	protected $params;
	protected $warnings = array();

	public function __construct(Platform $platform) {
		$this->platform = $platform;
		$this->params   = new Settings($platform);
	}

	protected function db() {
		return $this->platform->db();
	}

	public function getWarnings() {
		return $this->warnings;
	}

	public function logWarning($message) {
		$this->warnings[] = trim((string) $message);
	}

	/** The configured currency id, else the shop's default one. */
	protected function currencyId() {
		$id = (int) $this->params->get('default_currency_id', 0);
		if ($id > 0) {
			return $id;
		}
		return (int) hikashop_getCurrency();
	}

	protected function importSource() {
		return (string) $this->params->get('import_source', 'storelinkr');
	}
}
