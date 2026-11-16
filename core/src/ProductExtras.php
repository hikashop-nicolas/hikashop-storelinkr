<?php
namespace HikashopStorelinkr;

/**
 * What StoreLinkr's PIM sends beyond the catalogue basics, on products and on
 * variants alike (WooCommerce connector 2.20 and 2.21): the condition and its
 * description, and the metafields.
 */
class ProductExtras {

	/** The PIM's conditions, onto HikaShop's three (schema.org's names). */
	const CONDITIONS = array(
		'new'         => 'NewCondition',
		'refurbished' => 'RefurbishedCondition',
		'as new'      => 'UsedCondition',
		'good'        => 'UsedCondition',
		'used'        => 'UsedCondition',
		'reasonable'  => 'UsedCondition',
		'moderate'    => 'UsedCondition',
		'damaged'     => 'UsedCondition',
	);

	private static $customFields = null;

	/**
	 * Set the condition, its description and the metafields on a product to save.
	 * A key StoreLinkr does not send leaves what is stored: older versions of
	 * StoreLinkr send none of them.
	 */
	public static function apply($db, $element, array $data) {
		if (isset($data['condition'])) {
			$condition                                 = trim((string) $data['condition']);
			$element->{Schema::COL_CONDITION}          = $condition;
			$key                                       = strtolower($condition);
			$element->product_condition                = isset(self::CONDITIONS[ $key ]) ? self::CONDITIONS[ $key ] : '';
		} elseif (isset($data['isUsed'])) {
			$element->product_condition = ( (int) $data['isUsed'] === 1 ) ? 'UsedCondition' : 'NewCondition';
		}
		if (isset($data['conditionDescription'])) {
			$element->{Schema::COL_CONDITION_DESCRIPTION} = trim((string) $data['conditionDescription']);
		}
		if (isset($data['metafields']) && is_array($data['metafields'])) {
			self::metafields($db, $element, $data['metafields']);
		}
	}

	/**
	 * Every metafield, kept as JSON; one whose name is a HikaShop product custom
	 * field (by its column name) fills that field too.
	 */
	private static function metafields($db, $element, array $metafields) {
		$kept   = array();
		$fields = self::customFields($db);
		foreach ($metafields as $metafield) {
			$metafield = (array) $metafield;
			if (empty($metafield['name'])) {
				continue;
			}
			$type  = ! empty($metafield['type']) ? (string) $metafield['type'] : 'single_text';
			$value = isset($metafield['value']) ? $metafield['value'] : null;
			if (null === $value) {
				continue;
			}
			$kept[] = array(
				'name'  => (string) $metafield['name'],
				'type'  => $type,
				'value' => $value,
			);
			$column = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', (string) $metafield['name']), '_'));
			if (isset($fields[ $column ])) {
				$element->$column = is_scalar($value) ? (string) $value : json_encode($value);
			}
		}
		$element->{Schema::COL_METAFIELDS} = json_encode($kept);
	}

	/** The column names of HikaShop's product custom fields. */
	private static function customFields($db) {
		if (null === self::$customFields) {
			self::$customFields = array();
			try {
				$db->setQuery('SELECT field_namekey FROM ' . hikashop_table('field') . " WHERE field_table = 'product'");
				foreach ((array) $db->loadColumn() as $name) {
					self::$customFields[ strtolower((string) $name) ] = true;
				}
			} catch (\Exception $e) {
				self::$customFields = array();
			}
		}
		return self::$customFields;
	}

	/**
	 * A product the connector took offline (StoreLinkr removed it from the shop)
	 * comes back when StoreLinkr sends it again. One unpublished by hand stays so.
	 */
	public static function restoreIfTakenOffline($element, $existing) {
		if (! empty($existing) && ! empty($existing->{Schema::COL_OFFLINE})) {
			$element->product_published        = 1;
			$element->{Schema::COL_OFFLINE}    = 0;
		}
	}
}
