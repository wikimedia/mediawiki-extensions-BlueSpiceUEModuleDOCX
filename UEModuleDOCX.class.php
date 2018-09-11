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
 * @license    http://www.gnu.org/copyleft/gpl.html GNU Public License v3
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
		$this->setHook('BSUniversalExportGetWidget');
		$this->setHook('BSUniversalExportSpecialPageExecute');
		$this->setHook('LoadExtensionSchemaUpdates');
		$this->setHook('SkinTemplateOutputPageBeforeExec'); //LACK OF ICON
	}

	/**
	 * Sets up requires directories
	 * @param DatabaseUpdater $updater Provided by MediaWikis update.php
	 * @return boolean Always true to keep the hook running
	 */
	public function onLoadExtensionSchemaUpdates( $updater = null ) {
		//TODO: Create abstraction in Core/Adapter
		$sTmpDir = BS_DATA_DIR.'/UEModuleDOCX';
		if( !file_exists( $sTmpDir ) ) {
			echo 'Directory "'.$sTmpDir.'" not found. Creating.'."\n";
			mkdir( $sTmpDir );
		}
		else {
			echo 'Directory "'.$sTmpDir.'" found.'."\n";
		}

		return true;
	}

	/**
	 * Event-Handler method for the 'BSUniversalExportCreateWidget' event.
	 * Registers the DOCX Module with the UniversalExport Extension.
	 * @param SpecialUniversalExport $oSpecialPage
	 * @param string $sParam
	 * @param array $aModules
	 * @return true
	 */
	public function onBSUniversalExportSpecialPageExecute( $oSpecialPage, $sParam, &$aModules ) {
		$aModules['docx'] = new BsExportModuleDOCX();
		return true;
	}

	/**
	 * Hook-Handler method for the 'BSUniversalExportGetWidget' event.
	 * @param UniversalExport $oUniversalExport
	 * @param array $aModules
	 * @param Title $oSpecialPage
	 * @param Title $oCurrentTitle
	 * @param array $aCurrentQueryParams
	 * @return boolean
	 */
	public function onBSUniversalExportGetWidget( $oUniversalExport, &$aModules, $oSpecialPage, $oCurrentTitle, $aCurrentQueryParams ) {
		$aCurrentQueryParams['ue[module]'] = 'docx';
		$aLinks = array();
		$aLinks['docx-single'] = array(
			'URL'     => $oSpecialPage->getLinkUrl( $aCurrentQueryParams ),
			'TITLE'   => wfMessage( 'bs-uemoduledocx-widgetlink-single-title' )->plain(),
			'CLASSES' => 'bs-uemoduledocx-single',
			'TEXT'    => wfMessage( 'bs-uemoduledocx-widgetlink-single-text' )->plain(),
		);

		\Hooks::run( 'BSUEModuleDOCXBeforeCreateWidget', array( $this, $oSpecialPage, &$aLinks, $aCurrentQueryParams ) );

		$oDOCXView = new ViewBaseElement();
		$oDOCXView->setAutoWrap( '<ul>###CONTENT###</ul>' );
		$oDOCXView->setTemplate( '<li><a href="{URL}" rel="nofollow" title="{TITLE}" class="{CLASSES}">{TEXT}</a></li>' );#

		foreach( $aLinks as $sKey => $aData ) {
			$oDOCXView->addData( $aData );
		}

		$aModules[] = $oDOCXView;
		return true;
	}

	/**
	 * Adds an link to the headline
	 * NOT YET ENABLED BECAUSE LACK OF ICON!
	 * @param Skin $skin
	 * @param BaseTemplate $template
	 * @return boolean always true
	 */
	public function onSkinTemplateOutputPageBeforeExec(&$skin, &$template){
		$aCurrentQueryParams = $this->getRequest()->getValues();
		if ( isset( $aCurrentQueryParams['title'] ) ) {
			$sTitle = $aCurrentQueryParams['title'];
		} else {
			$sTitle = '';
		}
		$sSpecialPageParameter = BsCore::sanitize( $sTitle, '', BsPARAMTYPE::STRING );
		$oSpecialPage = SpecialPage::getTitleFor( 'UniversalExport', $sSpecialPageParameter );
		if ( isset( $aCurrentQueryParams['title'] ) ) unset( $aCurrentQueryParams['title'] );
		$aCurrentQueryParams['ue[module]'] = 'docx';
		$aContentActions = array(
			'id' => 'docx',
			'href' => $oSpecialPage->getLinkUrl( $aCurrentQueryParams ),
			'title' => wfMessage( 'bs-uemoduledocx-widgetlink-single-title' )->plain(),
			'text' => wfMessage( 'bs-uemoduledocx-widgetlink-single-text' )->plain(),
			'class' => 'bs-ue-export-link',
			'iconClass' => 'icon-file-word icon-image'
		);

		$template->data['bs_export_menu'][] = $aContentActions;
		return true;
	}
}
