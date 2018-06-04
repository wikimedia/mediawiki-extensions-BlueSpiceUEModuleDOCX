<?php
/**
 * BsDOCXTemplateProvider.
 *
 * Part of BlueSpice MediaWiki
 *
 * @author     Robert Vogel <vogel@hallowelt.com>
 * @version    $Id: DOCXTemplateProvider.class.php 8691 2013-02-21 15:56:27Z rvogel $
 * @package    BlueSpice_Extensions
 * @subpackage UEModulePDF
 * @copyright  Copyright (C) 2016 Hallo Welt! GmbH, All rights reserved.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU Public License v3
 * @filesource
 */

/**
 * UniversalExport BsDOCXTemplateProvider class.
 * @package BlueSpice_Extensions
 * @subpackage UEModulePDF
 */
class BsDOCXTemplateProvider {
	
	/**
	 * Provides a array suitable for the MediaWiki HtmlFormField class 
	 * HtmlSelectField.
	 * @param array $aParams Has to contain the 'template-path' that has to be
	 * searched for valid templates.
	 * @return array A options array for a HtmlSelectField
	 */
	public static function getTemplatesForSelectOptions( $aParams ) {
		$aOptions = array();
		try {
			$sPath = realpath( $aParams['template-path'] );
			$oDirIterator = new DirectoryIterator( $sPath );
			foreach( $oDirIterator as $oFileInfo ) {
				if( $oFileInfo->isDir() || $oFileInfo->isDot() ) continue;
				
				$sFileName = $oFileInfo->getBasename();
				$aFileNameParts = explode('.', $sFileName);
				$sFileExtension = array_pop( $aFileNameParts );
				if( strtoupper( $sFileExtension ) != 'DOCX' ) continue;

				$sTemplateName = implode('.', $aFileNameParts );
				$aOptions[$sTemplateName] = $sFileName;
			}
		}
		catch( Exception $e ) {
			wfDebugLog( 'BS::UEModuleDOCX', 'BsDOCXTemplateProvider::getTemplatesForSelectOptions: Error: '.$e->getMessage() );
			return array( '-' => '-' );
		}
		
		return $aOptions;
		
	}
}