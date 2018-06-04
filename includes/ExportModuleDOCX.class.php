<?php
/**
 * BsExportModuleDOCX.
 *
 * Part of BlueSpice MediaWiki
 *
 * @author     Robert Vogel <vogel@hallowelt.com>
 * @version    $Id: ExportModuleDOCX.class.php 9017 2013-03-25 09:22:09Z rvogel $
 * @package    BlueSpice_Extensions
 * @subpackage UEModuleDOCX
 * @copyright  Copyright (C) 2016 Hallo Welt! GmbH, All rights reserved.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU Public License v3
 * @filesource
 */

/**
 * UniversalExport BsExportModuleDOCX class.
 * @package BlueSpice_Extensions
 * @subpackage UEModuleDOCX
 */
class BsExportModuleDOCX implements BsUniversalExportModule {

	/**
	 * Implementation of BsUniversalExportModule interface.
	 * @param SpecialUniversalExport $oCaller
	 * @return array array( 'mime-type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'filename' => 'Filename.docx', 'content' => '8F3BC3025A7...' );
	 */
	public function createExportFile( &$oCaller ) {
		global $wgRequest;

		$oCaller->aParams['title']         = $oCaller->oRequestedTitle->getPrefixedText();
		$oCaller->aParams['display-title'] = $oCaller->oRequestedTitle->getPrefixedText();
		$oCaller->aParams['article-id'] = $oCaller->oRequestedTitle->getArticleID();
		$oCaller->aParams['oldid']      = $wgRequest->getInt( 'oldid', 0 );

		$config = \BlueSpice\Services::getInstance()->getConfigFactory()
			->makeConfig( 'bsg' );

		if( $config->get( 'UEModuleDOCXSuppressNS' ) ) {
			$oCaller->aParams['display-title'] = $oCaller->oRequestedTitle->getText();
		}
		//If we are in history mode and we are relative to an oldid
		$oCaller->aParams['direction'] = $wgRequest->getVal('direction', '');
		if( !empty( $oCaller->aParams['direction'] ) ) {
			$oCurrentRevision = Revision::newFromId( $oCaller->aParams['oldid'] );
			switch( $oCaller->aParams['direction'] ) {
				case 'next': $oCurrentRevision = $oCurrentRevision->getNext();
					break;
				case 'prev': $oCurrentRevision = $oCurrentRevision->getPrevious();
					break;
				default: break;
			}
			if( $oCurrentRevision !== null ) {
				$oCaller->aParams['oldid'] = $oCurrentRevision->getId();
			}
		}

		$oCaller->aParams['document-token'] = md5( $oCaller->oRequestedTitle->getPrefixedText() ).'-'.$oCaller->aParams['oldid'];
		$oCaller->aParams['backend-url'] = $config->get(
			'UEModuleDOCXDOCXServiceURL'
		);

		$sTemplate = $config->get( 'UEModuleDOCXTemplatePath' )
			.'/'
			.$config->get( 'UEModuleDOCXDefaultTemplate' );
		$sTemplate = realpath( $sTemplate );

		$aPage = BsDOCXPageProvider::getPage( $oCaller->aParams );

		wfRunHooks( 'BSUEModuleDOCXBeforeCreateDOCX', array( $this, &$sTemplate, $oCaller ) );

		$oDOCXBackend = new BsDOCXServlet( $oCaller->aParams );

		//Prepare response
		$aResponse = array(
			'mime-type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'filename'  => '%s.docx',
			'content'   => ''
		);

		if ( RequestContext::getMain()->getRequest()->getVal( 'debugformat', '' ) == 'html' ) {
			$aResponse['content'] = $aPage['dom']->saveHTML();
			$aResponse['mime-type'] = 'text/html';
			$aResponse['filename'] = sprintf(
				'%s.html',
				$oCaller->oRequestedTitle->getPrefixedText()
			);
			$aResponse['disposition'] = 'inline';
			return $aResponse;
		}

		$aResponse['content'] = $oDOCXBackend->createDOCX($aPage['dom'], $sTemplate);

		$aResponse['filename'] = sprintf(
			$aResponse['filename'],
			$oCaller->oRequestedTitle->getPrefixedText()
		);

		return $aResponse;
	}

	/**
	 * Implementation of BsUniversalExportModule interface. Creates an overview
	 * over the PdfExportModule
	 * @return ViewExportModuleOverview
	 */
	public function getOverview() {
		$oModuleOverviewView = new ViewExportModuleOverview();

		$oModuleOverviewView->setOption( 'module-title', wfMessage( 'bs-uemoduledocx-overview-title' )->text() );
		$oModuleOverviewView->setOption( 'module-description', wfMessage( 'bs-uemoduledocx-overview-description' )->text() );
		$oModuleOverviewView->setOption( 'module-bodycontent', '' );

		$oWebserviceStateView = new ViewBaseElement();
		$oWebserviceStateView->setTemplate(
			'{LABEL}: <span style="font-weight: bold; color:{COLOR}">{STATE}</span>'
			);

		$config = \BlueSpice\Services::getInstance()->getConfigFactory()
			->makeConfig( 'bsg' );

		$sWebServiceUrl = $config->get( 'UEModuleDOCXDOCXServiceURL' );
		$sWebserviceState = wfMessage('bs-uemoduledocx-overview-webservice-state-not-ok')->plain();
		$sColor = 'red';
		if( BsConnectionHelper::testUrlForTimeout( $sWebServiceUrl ) ) {
			$sColor = 'green';
			$sWebserviceState = wfMessage('bs-uemoduledocx-overview-webservice-state-ok')->plain();

			$oWebserviceUrlView = new ViewBaseElement();
			$oWebserviceUrlView->setTemplate(
				'{LABEL}: <a href="{URL}" target="_blank">{URL}</a><br/>'
			);
			$oWebserviceUrlView->addData(array(
				'LABEL' => wfMessage('bs-uemoduledocx-overview-webservice-webadmin')->plain(),
				'URL'   => $sWebServiceUrl,
			));
			$oModuleOverviewView->addItem( $oWebserviceUrlView );
		}

		$oWebserviceStateView->addData(array(
			'LABEL' => wfMessage('bs-uemoduledocx-overview-webservice-state')->plain(),
			'COLOR' => $sColor,
			'STATE' => $sWebserviceState
		));

		$oModuleOverviewView->addItem( $oWebserviceStateView );

		return $oModuleOverviewView;
	}
}
