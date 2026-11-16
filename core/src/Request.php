<?php
namespace HikashopStorelinkr;

/**
 * One call from StoreLinkr: its HTTP method, its path under storelinkr/v1, its
 * headers and its parameters (query and JSON body merged). The platform builds
 * it from what the CMS received; field access mirrors the WooCommerce
 * connector's $request[...] and validateRequiredFields() (isset-style, so null
 * is missing).
 */
class Request {
	public $method = 'GET';
	public $path = '';
	public $params = array();
	private $headers = array();

	/**
	 * @param string $method  The HTTP method (an X-HTTP-Method-Override header wins).
	 * @param string $path    The path under storelinkr/v1, e.g. products/12/update.
	 * @param array  $headers Header name => value, any case.
	 * @param array  $params  Query and body parameters.
	 */
	public function __construct($method, $path, array $headers, array $params) {
		foreach ($headers as $name => $value) {
			$this->headers[ strtolower(str_replace('_', '-', (string) $name)) ] = is_array($value) ? reset($value) : $value;
		}
		$override     = $this->header('X-HTTP-Method-Override');
		$this->method = strtoupper(! empty($override) ? (string) $override : (string) $method);
		$this->path   = trim((string) $path, '/');
		unset($params['option'], $params['ctrl'], $params['task'], $params['slr'], $params['Itemid'], $params['lang'], $params['rest_route']);
		$this->params = $params;
	}

	public function get($key, $fallback = null) {
		return array_key_exists($key, $this->params) ? $this->params[ $key ] : $fallback;
	}

	public function has($key) {
		return array_key_exists($key, $this->params) && null !== $this->params[ $key ];
	}

	public function setParam($key, $value) {
		$this->params[ $key ] = $value;
	}

	public function all() {
		return $this->params;
	}

	/** Throw on the first missing field, as validateRequiredFields() does. */
	public function requireFields(array $fields) {
		foreach ($fields as $field) {
			if (! $this->has($field)) {
				ApiError::raise('Missing data field: ' . $field);
			}
		}
	}

	public function header($name) {
		$name = strtolower((string) $name);
		return isset($this->headers[ $name ]) ? $this->headers[ $name ] : null;
	}
}
