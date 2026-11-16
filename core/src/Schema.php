<?php
namespace HikashopStorelinkr;

/**
 * Lazily ensures the product columns StoreLinkr needs exist. HikaShop has no
 * native EAN/UUID column, so we add our own (indexed EAN for fast lookups),
 * plus the PIM condition, the metafields, and a flag on the products the
 * connector itself took offline. Setting these scalar properties on a product object persists
 * them through hikashopClass::save(), which only writes real table columns.
 * Idempotent: ALTER errors (column already present) are swallowed.
 */

class Schema {
	const COL_EAN = 'storelinkr_ean';
	const COL_UUID = 'storelinkr_uuid';
	const COL_SOURCE = 'storelinkr_source';
	const COL_CONDITION = 'storelinkr_condition';
	const COL_CONDITION_DESCRIPTION = 'storelinkr_condition_description';
	const COL_METAFIELDS = 'storelinkr_metafields';
	const COL_OFFLINE = 'storelinkr_offline';

	private static $ensured = false;

	public static function ensure($db) {
		if(self::$ensured) {
			return;
		}
		self::$ensured = true;

		$table = hikashop_table('product');
		$existing = array();
		try {
			$db->setQuery('SHOW COLUMNS FROM ' . $table);
			foreach((array)$db->loadObjectList() as $col) {
				$existing[strtolower($col->Field)] = true;
			}
		} catch (\Exception $e) {
			return;
		}

		$columns = array(
			self::COL_EAN => 'VARCHAR(64) NULL',
			self::COL_UUID => 'VARCHAR(191) NULL',
			self::COL_SOURCE => 'VARCHAR(64) NULL',
			self::COL_CONDITION => 'VARCHAR(64) NULL',
			self::COL_CONDITION_DESCRIPTION => 'TEXT NULL',
			self::COL_METAFIELDS => 'MEDIUMTEXT NULL',
			self::COL_OFFLINE => 'TINYINT(1) NULL',
		);
		foreach($columns as $name => $definition) {
			if(isset($existing[strtolower($name)])) {
				continue;
			}
			try {
				$db->setQuery('ALTER TABLE ' . $table . ' ADD `' . $name . '` ' . $definition);
				$db->execute();
			} catch (\Exception $e) {
				// Column may already exist or DDL may be restricted; matching by
				// SKU still works without it.
			}
		}

		// Index the EAN column for the lookups StoreLinkr does on every upsert.
		// Only when missing: re-running ADD INDEX errors, and WordPress's DB
		// layer logs that error even when the exception is caught.
		$hasIndex = false;
		try {
			$db->setQuery('SHOW INDEX FROM ' . $table . " WHERE Key_name = 'idx_storelinkr_ean'");
			$hasIndex = !empty($db->loadObjectList());
		} catch (\Exception $e) {
			$hasIndex = true; // can't tell: do not attempt the ALTER
		}
		if(!$hasIndex) {
			try {
				$db->setQuery('ALTER TABLE ' . $table . ' ADD INDEX `idx_storelinkr_ean` (`' . self::COL_EAN . '`)');
				$db->execute();
			} catch (\Exception $e) {
				// Index already present or DDL restricted.
			}
		}
	}
}
