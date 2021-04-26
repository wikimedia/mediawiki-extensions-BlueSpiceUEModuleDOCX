<?php
/**
 * ExportModuleDOCX.
 *
 * Part of BlueSpice MediaWiki
 *
 * @author     Robert Vogel <vogel@hallowelt.com>
 * @version    $Id: ExportModuleDOCX.php 9017 2013-03-25 09:22:09Z rvogel $
 * @package    BlueSpice_Extensions
 * @subpackage UEModuleDOCX
 * @copyright  Copyright (C) 2016 Hallo Welt! GmbH, All rights reserved.
 * @license    http://www.gnu.org/copyleft/gpl.html GPL-3.0-only
 * @filesource
 */

use BlueSpice\UEModuleDOCX\ExportSubaction\Subpages;
use BlueSpice\UniversalExport\ExportModule;
use BlueSpice\UniversalExport\ExportSpecification;
use MediaWiki\MediaWikiServices;

/**
 * UniversalExport ExportModuleDOCX class.
 * @package BlueSpice_Extensions
 * @subpackage UEModuleDOCX
 */
class ExportModuleDOCX extends ExportModule {

	/**
	 * @inheritDoc
	 */
	protected function setParams( &$specification ) {
		parent::setParams( $specification );
		if ( $this->config->get( 'UEModuleDOCXSuppressNS' ) ) {
			$specification->setParam( 'display-title', $specification->getTitle()->getText() );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getExportPermission() {
		return 'uemoduledocx-export';
	}

	/**
	 * @inheritDoc
	 */
	protected function setExportConnectionParams( ExportSpecification &$specification ) {
		parent::setExportConnectionParams( $specification );
		$specification->setParam( 'backend-url', $this->config->get(
			'UEModuleDOCXDOCXServiceURL'
		) );
	}

	/**
	 * @inheritDoc
	 */
	protected function getTemplateParams( $specification, $page ) {
		$params = parent::getTemplateParams( $specification, $page );

		$templatePath = $this->config->get( 'UEModuleDOCXTemplatePath' ) . '/' .
			$this->config->get( 'UEModuleDOCXDefaultTemplate' );

		return array_merge( $params, [
			'path' => $templatePath,
			'realpath' => realpath( $templatePath ),
			'dom' => $page['dom'],
		] );
	}

	/**
	 * @inheritDoc
	 */
	protected function getTemplate( $params ) {
		return DOCXTemplateProvider::getTemplate( $params );
	}

	/**
	 * @inheritDoc
	 */
	protected function getPage( ExportSpecification $specification ) {
		return DOCXPageProvider::getPage( $specification->getParams() );
	}

	/**
	 * @inheritDoc
	 */
	protected function modifyTemplateAfterContents( &$template, $page, $specification ) {
		MediaWikiServices::getInstance()->getHookContainer()->run(
			'BSUEModuleDOCXBeforeCreateDOCX',
			[
				$this,
				&$template,
				$specification
			]
		);
	}

	/**
	 * @inheritDoc
	 */
	protected function getResponseParams() {
		return array_merge(
			parent::getResponseParams(),
			[
				'mime-type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'filename'  => '%s.docx',
			]
		);
	}

	/**
	 * @inheritDoc
	 */
	protected function getExportedContent( $specification, &$template ) {
		$params = $specification->getParams();
		$backend = new DOCXServlet( $params );
		return $backend->createDOCX( $template['dom'], $template['realpath'] );
	}

	/**
	 * Implementation of BsUniversalExportModule interface. Creates an overview
	 * over the PdfExportModule
	 * @return ViewExportModuleOverview
	 */
	public function getOverview() {
		$moduleOverviewView = new ViewExportModuleOverview();

		$moduleOverviewView->setOption( 'module-title', wfMessage( 'bs-uemoduledocx-overview-title' )
			->text() );
		$moduleOverviewView
			->setOption( 'module-description', wfMessage( 'bs-uemoduledocx-overview-description' )
			->text() );
		$moduleOverviewView->setOption( 'module-bodycontent', '' );

		$webserviceStateView = new ViewBaseElement();
		$webserviceStateView->setTemplate(
			'{LABEL}: <span style="font-weight: bold; color:{COLOR}">{STATE}</span>'
			);

		$config = MediaWikiServices::getInstance()->getConfigFactory()
			->makeConfig( 'bsg' );

		$webServiceUrl = $config->get( 'UEModuleDOCXDOCXServiceURL' );
		$webserviceState = wfMessage( 'bs-uemoduledocx-overview-webservice-state-not-ok' )->plain();
		$color = 'red';
		if ( BsConnectionHelper::testUrlForTimeout( $webServiceUrl ) ) {
			$color = 'green';
			$webserviceState = wfMessage( 'bs-uemoduledocx-overview-webservice-state-ok' )->plain();

			$webserviceUrlView = new ViewBaseElement();
			$webserviceUrlView->setTemplate(
				'{LABEL}: <a href="{URL}" target="_blank">{URL}</a><br/>'
			);
			$webserviceUrlView->addData( [
				'LABEL' => wfMessage( 'bs-uemoduledocx-overview-webservice-webadmin' )->plain(),
				'URL'   => $webServiceUrl,
			] );
			$moduleOverviewView->addItem( $webserviceUrlView );
		}

		$webserviceStateView->addData( [
			'LABEL' => wfMessage( 'bs-uemoduledocx-overview-webservice-state' )->plain(),
			'COLOR' => $color,
			'STATE' => $webserviceState
		] );

		$moduleOverviewView->addItem( $webserviceStateView );

		return $moduleOverviewView;
	}

	/**
	 * @inheritDoc
	 */
	public function getSubactionHandlers() {
		return [
			'subpages' => Subpages::factory( $this )
		];
	}

	/**
	 * @inheritDoc
	 */
	public function getActionButtonDetails() {
		return [
			'title' => \Message::newFromKey( 'bs-uemoduledocx-widgetlink-single-text' ),
			'text' => \Message::newFromKey( 'bs-uemoduledocx-widgetlink-single-text' ),
			'iconClass' => 'icon-file-word'
		];
	}
}
