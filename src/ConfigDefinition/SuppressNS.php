<?php

namespace BlueSpice\UEModuleDOCX\ConfigDefinition;

use BlueSpice\ConfigDefinition\BooleanSetting;

class SuppressNS extends BooleanSetting {

	public function getPaths() {
		return [
			static::MAIN_PATH_FEATURE . '/' . static::FEATURE_EXPORT . '/UEModuleDOCX',
			static::MAIN_PATH_EXTENSION . '/UEModuleDOCX/' . static::FEATURE_EXPORT,
			static::MAIN_PATH_PACKAGE . '/' . static::PACKAGE_PRO . '/UEModuleDOCX',
		];
	}

	public function getLabelMessageKey() {
		return 'bs-uemoduledocx-pref-SuppressNS';
	}
}
