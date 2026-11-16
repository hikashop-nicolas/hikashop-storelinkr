<?php
namespace HikashopStorelinkr;

/**
 * Base of the resource controllers: the request, the platform and the
 * settings, the services it used (loaded on demand) and their warnings, which
 * are returned to StoreLinkr the way the WooCommerce connector returns them.
 */
abstract class Controller {
	/** @var Request */
	protected $request;
	/** @var Platform */
	protected $platform;
	/** @var Settings */
	protected $params;
	protected $services = array();

	public function __construct(Request $request, Platform $platform) {
		$this->request  = $request;
		$this->platform = $platform;
		$this->params   = new Settings($platform);
	}

	/** A service, e.g. 'ProductService', created once per request. */
	protected function service($name) {
		if (! isset($this->services[ $name ])) {
			$class                    = __NAMESPACE__ . '\\Service\\' . $name;
			$this->services[ $name ] = new $class($this->platform);
		}
		return $this->services[ $name ];
	}

	protected function db() {
		return $this->platform->db();
	}

	/** The source label sent with imported records, else the configured one. */
	protected function importSource() {
		$src = $this->request->get('importSource', $this->request->get('import_source', ''));
		if ('' === $src || null === $src) {
			$src = (string) $this->params->get('import_source', 'storelinkr');
		}
		return (string) $src;
	}

	/** The warnings of every service this controller used. */
	protected function warnings() {
		$warnings = array();
		foreach ($this->services as $svc) {
			$warnings = array_merge($warnings, $svc->getWarnings());
		}
		return $warnings;
	}
}
