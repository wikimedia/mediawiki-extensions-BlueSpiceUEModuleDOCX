<?php

namespace BlueSpice\UEModuleDOCX\Hook\ChameleonSkinTemplateOutputPageBeforeExec;

use BlueSpice\Hook\ChameleonSkinTemplateOutputPageBeforeExec;
use BlueSpice\UniversalExport\ModuleFactory;

class AddWidget extends ChameleonSkinTemplateOutputPageBeforeExec {
	/**
	 *
	 * @return bool
	 */
	protected function skipProcessing() {
		return !$this->getServices()->getSpecialPageFactory()->exists( 'UniversalExport' );
	}

	protected function doProcess() {
		/** @var ModuleFactory $moduleFactory */
		$moduleFactory = $this->getServices()->getService(
			'BSUniversalExportModuleFactory'
		);
		$module = $moduleFactory->newFromName( 'docx' );

		$contentActions = [
			'id' => 'docx',
			'href' => $module->getExportLink( $this->getContext()->getRequest() ),
			'title' => $this->msg( 'bs-uemoduledocx-widgetlink-single-title' )->plain(),
			'text' => $this->msg( 'bs-uemoduledocx-widgetlink-single-text' )->plain(),
			'class' => 'bs-ue-export-link',
			'iconClass' => 'icon-file-word'
		];

		$this->template->data['bs_export_menu'][] = $contentActions;
	}

}
