<?php
namespace HikashopStorelinkr;

/** The connector's settings, read through the platform (get() as Joomla's Registry). */
class Settings {
	private $platform;

	public function __construct(Platform $platform) {
		$this->platform = $platform;
	}

	public function get($key, $fallback = null) {
		$value = $this->platform->setting($key, $fallback);
		return ( null === $value || '' === $value ) ? $fallback : $value;
	}
}
