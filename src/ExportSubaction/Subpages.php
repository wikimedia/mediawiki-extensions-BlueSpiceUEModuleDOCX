<?php

namespace BlueSpice\UEModuleDOCX\ExportSubaction;

use BlueSpice\UniversalExport\ExportSubaction\Subpages as SubpagesBase;
use DOCXPageProvider;
use ExportModuleDOCX;

class Subpages extends SubpagesBase {
	/** @var ExportModuleDOCX */
	protected $module;

	/**
	 * @param ExportModuleDOCX $module
	 * @return static
	 */
	public static function factory( ExportModuleDOCX $module ) {
		return new static( $module );
	}

	/**
	 *
	 * @param ExportModuleDOCX $moduleDOCX
	 */
	public function __construct( ExportModuleDOCX $moduleDOCX ) {
		$this->module = $moduleDOCX;
	}

	/**
	 * @return string
	 */
	public function getPermission() {
		return 'uemoduledocxsubpages-export';
	}

	/**
	 * @return mixed
	 */
	protected function getPageProvider() {
		return new DOCXPageProvider();
	}

	/**
	 * @inheritDoc
	 */
	public function getActionButtonDetails() {
		return [
			'title' => \Message::newFromKey( 'bs-uemoduledocx-widgetlink-subpages-title' ),
			'text' => \Message::newFromKey( 'bs-uemoduledocx-widgetlink-subpages-text' ),
			'iconClass' => 'icon-file-word'
		];
	}

	/**
	 * @inheritDoc
	 */
	public function getMainModule() {
		return $this->module;
	}
}
