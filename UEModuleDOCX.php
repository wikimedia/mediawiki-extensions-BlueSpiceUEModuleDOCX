<?php
/**
 * UniversalExport DOCX Module extension for BlueSpice
 *
 * Enables MediaWiki to export pages into DOCX format.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, version 3.
 *
 * This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 *
 * This file is part of BlueSpice MediaWiki
 * For further information visit http://bluespice.com
 *
 * @author     Robert Vogel <vogel@hallowelt.com>
 * @package    BlueSpice_Extensions
 * @subpackage UEModuleDOCX
 * @copyright  Copyright (C) 2016 Hallo Welt! GmbH, All rights reserved.
 * @license    http://www.gnu.org/copyleft/gpl.html GPL-3.0-only
 * @filesource
 */

/**
 * Base class for UniversalExport DOCX Module extension
 * @package BlueSpice_Extensions
 * @subpackage UEModuleDOCX
 */
class UEModuleDOCX extends BsExtensionMW {

	/**
	 * Initialization of UEModuleDOCX extension
	 */
	protected function initExt() {
		$this->setHook( 'BSUniversalExportGetWidget' );
		$this->setHook( 'BSUniversalExportSpecialPageExecute' );
		// LACK OF ICON
		$this->setHook( 'SkinTemplateOutputPageBeforeExec' );
	}

	/**
	 * Event-Handler method for the 'BSUniversalExportCreateWidget' event.
	 * Registers the DOCX Module with the UniversalExport Extension.
	 * @param SpecialUniversalExport $specialPage
	 * @param string $param
	 * @param array &$modules
	 * @return true
	 */
	public function onBSUniversalExportSpecialPageExecute( $specialPage, $param, &$modules ) {
		$modules['docx'] = new ExportModuleDOCX();
		return true;
	}

	/**
	 * Hook-Handler method for the 'BSUniversalExportGetWidget' event.
	 * @param UniversalExport $universalExport
	 * @param array &$modules
	 * @param Title $specialPage
	 * @param Title $currentTitle
	 * @param array $currentQueryParams
	 * @return bool
	 */
	public function onBSUniversalExportGetWidget(
		$universalExport,
		&$modules,
		$specialPage,
		$currentTitle,
		$currentQueryParams
	) {
		$currentQueryParams['ue[module]'] = 'docx';
		$links = [];
		$links['docx-single'] = [
			'URL'     => $specialPage->getLinkUrl( $currentQueryParams ),
			'TITLE'   => wfMessage( 'bs-uemoduledocx-widgetlink-single-title' )->plain(),
			'CLASSES' => 'bs-uemoduledocx-single',
			'TEXT'    => wfMessage( 'bs-uemoduledocx-widgetlink-single-text' )->plain(),
		];

		\Hooks::run(
			'BSUEModuleDOCXBeforeCreateWidget',
			[ $this, $specialPage, &$links, $currentQueryParams ]
		);

		$DOCXView = new ViewBaseElement();
		$DOCXView->setAutoWrap( '<ul>###CONTENT###</ul>' );
		$DOCXView->setTemplate(
			'<li><a href="{URL}" rel="nofollow" title="{TITLE}" class="{CLASSES}">{TEXT}</a></li>'
		);

		foreach ( $links as $key => $data ) {
			$DOCXView->addData( $data );
		}

		$modules[] = $DOCXView;
		return true;
	}

	/**
	 * Adds an link to the headline
	 * NOT YET ENABLED BECAUSE LACK OF ICON!
	 * @param Skin &$skin
	 * @param BaseTemplate &$template
	 * @return bool always true
	 */
	public function onSkinTemplateOutputPageBeforeExec( &$skin, &$template ) {
		$currentQueryParams = $this->getRequest()->getValues();
		if ( isset( $currentQueryParams['title'] ) ) {
			$title = $currentQueryParams['title'];
		} else {
			$title = '';
		}
		$specialPageParameter = BsCore::sanitize( $title, '', BsPARAMTYPE::STRING );
		$specialPage = SpecialPage::getTitleFor( 'UniversalExport', $specialPageParameter );
		if ( isset( $currentQueryParams['title'] ) ) { unset( $currentQueryParams['title'] );
		}
		$currentQueryParams['ue[module]'] = 'docx';
		$contentActions = [
			'id' => 'docx',
			'href' => $specialPage->getLinkUrl( $currentQueryParams ),
			'title' => wfMessage( 'bs-uemoduledocx-widgetlink-single-title' )->plain(),
			'text' => wfMessage( 'bs-uemoduledocx-widgetlink-single-text' )->plain(),
			'class' => 'bs-ue-export-link',
			'iconClass' => 'icon-file-word'
		];

		$template->data['bs_export_menu'][] = $contentActions;
		return true;
	}
}
