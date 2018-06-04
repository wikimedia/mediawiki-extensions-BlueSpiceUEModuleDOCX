<?php
/**
 * BsDOCXPageProvider.
 *
 * Part of BlueSpice MediaWiki
 *
 * @author     Robert Vogel <vogel@hallowelt.com>
  * @package    BlueSpice_Extensions
 * @subpackage UEModuleDOCX
 * @copyright  Copyright (C) 2016 Hallo Welt! GmbH, All rights reserved.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU Public License v3
 * @filesource
 */

/**
 * UniversalExport BsDOCXPageProvider class.
 * @package BlueSpice_Extensions
 * @subpackage UEModuleDOCX
 */
class BsDOCXPageProvider {

	/**
	 * Fetches the requested pages markup, cleans it and returns a DOMDocument.
	 * @param array $aParams Needs the 'article-id' key to be set and valid.
	 * @return array 
	 */
	public static function getPage( $aParams ) {
		wfRunHooks( 'BSUEModuleDOCXbeforeGetPage', array( &$aParams ) );
		
		$oTitle = Title::newFromID($aParams['article-id']);
		if( $oTitle == null ){
			$oTitle = Title::newFromText($aParams['title']);
		}
		
		$oPCP = new BsPageContentProvider();
		$oPageDOM = $oPCP->getDOMDocumentContentFor( 
			$oTitle, 
			$aParams + array( 'follow-redirects' => true )
		); // TODO RBV (06.12.11 17:09): Follow Redirect... setting or default?

		//Collect Metadata
		$aData = self::collectData( $oTitle, $oPageDOM, $aParams );

		//Cleanup DOM
		self::cleanUpDOM( $oTitle, $oPageDOM, $aParams );
		
		$oDOMXPath = new DOMXPath( $oPageDOM );
		$oFirstHeading = $oDOMXPath->query( "//*[contains(@class, 'firstHeading')]" )->item(0);
		$oBodyContent  = $oDOMXPath->query( "//*[contains(@class, 'bodyContent')]" )->item(0);

		if( isset($aParams['display-title'] ) ) {
			$oFirstHeading->nodeValue = $aParams['display-title'];
			$aData['meta']['title']   = $aParams['display-title'];
		}
		
		$aPage = array(
			'dom' => $oPageDOM,
			'firstheading-element' => $oFirstHeading,
			'bodycontent-element'  => $oBodyContent,
			'meta'             => $aData['meta']
		);
		
		wfRunHooks( 'BSUEModuleDOCXgetPage', array( $oTitle, &$aPage, &$aParams, $oDOMXPath ) );
		return $aPage;
	}

	/**
	 * Collects metadata and additional resources for this page
	 * @param Title $oTitle
	 * @param DOMDocument $oPageDOM
	 * @param array $aParams
	 * @return array array( 'meta' => ..., 'resources' => ...);
	 */
	private static function collectData( $oTitle, $oPageDOM, $aParams ) {
		$aMeta = array();
		
		// TODO RBV (01.02.12 13:51): Handle oldid
		$aCategories = array();
		if( $oTitle->exists() ) {
			// TODO RBV (27.06.12 11:47): Throws an exception. Maybe better use try ... catch instead of $oTitle->exists()
			$aAPIParams = new FauxRequest( array(
					'action' => 'parse',
					//'oldid'  => ,
					'page'  => $oTitle->getPrefixedText(),
					'prop'   => 'images|categories|links'
			));

			$oAPI = new ApiMain( $aAPIParams );
			$oAPI->execute();

			$aResult = $oAPI->getResultData();

			foreach($aResult['parse']['categories'] as $aCat ) {
				$aCategories[] = $aCat['*'];
			}
		}
		
		//Dublin Core:
		$aMeta['DC.title'] = $oTitle->getPrefixedText();
		$aMeta['DC.date']  = wfTimestamp( TS_ISO_8601 ); // TODO RBV (14.12.10 14:01): Check for conformity. Maybe there is a better way to acquire than wfTimestamp()?

		//Custom
		global $wgLang;
		$sCurrentTS = $wgLang->userAdjust( wfTimestampNow() );
		$aMeta['title']           = $oTitle->getPrefixedText();
		$aMeta['exportdate']      = $wgLang->sprintfDate( 'd.m.Y', $sCurrentTS );
		$aMeta['exporttime']      = $wgLang->sprintfDate( 'H:i', $sCurrentTS );
		$aMeta['exporttimeexact'] = $wgLang->sprintfDate( 'H:i:s', $sCurrentTS );
		
		//Custom - Categories->Keywords
		$aMeta['keywords'] = implode( ', ', $aCategories );

		$oDOMXPath = new DOMXPath( $oPageDOM );
		$oMetadataElements = $oDOMXPath->query( "//div[@class='bs-universalexport-meta']" );
		foreach( $oMetadataElements as $oMetadataElement ) {
			if( $oMetadataElement->hasAttributes() ) {
				foreach( $oMetadataElement->attributes as $oAttribute ) {
					if( $oAttribute->name !== 'class' ) {
						$aMeta[ $oAttribute->name ] = $oAttribute->value;
					}
				}
			}
			$oMetadataElement->parentNode->removeChild( $oMetadataElement );
		}
		
		//If it's a normal article
		if( !in_array( $oTitle->getNamespace(), array( NS_SPECIAL, NS_IMAGE, NS_CATEGORY ) ) ) {
			$oArticle = new Article($oTitle);
			$aMeta['author'] = $oArticle->getUserText(); // TODO RBV (14.12.10 12:19): Realname/Username -> DisplayName
			$aMeta['date']   = $wgLang->sprintfDate( 'd.m.Y', $oArticle->getTouched() );
		}

		wfRunHooks( 'BSUEModuleDOCXcollectMetaData', array( $oTitle, $oPageDOM, &$aParams, $oDOMXPath, &$aMeta ) );

		$config = \BlueSpice\Services::getInstance()->getConfigFactory()
			->makeConfig( 'bsg' );

		$aMetaDataOverrides = \FormatJson::decode(
			$config->get( 'UniversalExportMetadataOverrides' ),
			true
		);
		$aMeta = array_merge( $aMeta, $aMetaDataOverrides );
		
		return array( 'meta' => $aMeta );
	}

	/**
	 * Cleans the DOM: removes editsections, script tags, some elementy 
	 * by classes, makes links absolute and pages paginatable and prevents 
	 * large images from clipping in the DOCX
	 * @param Title $oTitle
	 * @param DOMDocument $oPageDOM
	 * @param array $aParams 
	 */
	private static function cleanUpDOM( $oTitle, $oPageDOM, $aParams ) {
		$aClassesToRemove = array( 'editsection', 'bs-universalexport-exportexclude', 'magnify' );
		$oDOMXPath = new DOMXPath($oPageDOM );
		wfRunHooks( 'BSUEModuleDOCXcleanUpDOM', array( $oTitle, $oPageDOM, &$aParams, $oDOMXPath, &$aClassesToRemove ) );

		//Remove script-Tags
		foreach( $oPageDOM->getElementsByTagName( 'script' ) as $oScriptElement ) {
			$oScriptElement->parentNode->removeChild( $oScriptElement );
		}

		//Remove elements by class
		$aContainsStmnts = array();
		foreach( $aClassesToRemove as $sClass ){
			$aContainsStmnts[] = "contains(@class, '".$sClass."')";
		}
		
		//Remove jumpmark anchors as Word doesn't need them and they may create unwanted linebreaks
		$aContainsStmnts[] = "contains(@name, 'bs-ue-jumpmark-')";
		
		$sXPath = '//*['.implode(' or ', $aContainsStmnts ).']';

		$oElementsToRemove = $oDOMXPath->query( $sXPath );
		foreach( $oElementsToRemove as $oElementToRemove ) {
			$oElementToRemove->parentNode->removeChild( $oElementToRemove );
		}

		//Make internal hyperlinks absolute
		global $wgServer;
		$oInternalAnchorElements = $oDOMXPath->query( "//a[not(contains(@class, 'external')) and not(starts-with(@href, '#'))]" ); //No external and no jumplinks
		foreach( $oInternalAnchorElements as $oInternalAnchorElement ) {
			$sRelativePath = $oInternalAnchorElement->getAttribute( 'href' );
			$oInternalAnchorElement->setAttribute(
				'href',
				$wgServer.$sRelativePath
			);
		}
		
		//TOC is not needed as Word generates one
		$oTOCULElement = $oDOMXPath->query( "//*[contains(@id, 'toc')]" )->item(0);
		if( $oTOCULElement instanceof DOMElement ) {
			$oTOCULElement->parentNode->removeChild( $oTOCULElement );
		}
		
		$oImageLink = $oDOMXPath->query( "//a[contains(@class, 'image')]" );
		foreach( $oImageLink as $oImageLink ){
			$oParent = BsDOMHelper::getParentDOMElement( $oImageLink );
			$oImage  = BsDOMHelper::getFirstDOMElementChild( $oImageLink );
			$aClasses = explode( ' ', $oParent->getAttribute('class') );
			if(in_array('thumbinner', $aClasses)) {
				$oParent = BsDOMHelper::getParentDOMElement( $oParent );
				$aClasses = explode( ' ', $oParent->getAttribute('class') );
			}
			
			$aIntersect = array_intersect( $aClasses, array('floatleft', 'tleft'));
			if( !empty($aIntersect)) {
				$oImage->setAttribute('align', 'left');
			}
			$aIntersect = array_intersect( $aClasses, array('floatright', 'tright'));
			if( !empty( $aIntersect )) {
				$oImage->setAttribute('align', 'right');
			}
			//$oParent->parentNode->insertBefore( $oImage );
			//$oParent->parentNode->removeChild($oParent);
		}
		
		//TODO: Should this be in DocxServlet::findFiles()?
		//Prevent large images from clipping
		foreach( $oPageDOM->getElementsByTagName( 'img' ) as $oImgElement ) {
			$iWidth = $oImgElement->getAttribute( 'width' );
			if( $iWidth > 700 ) {
				$oImgElement->setAttribute( 'width', 700 );
				$oImgElement->removeAttribute( 'height' );
				
				$sClasses = $oImgElement->getAttribute( 'class' );
				$oImgElement->setAttribute( 'class', $sClasses.' maxwidth' );
			}
			
			//Remove surrounding anchor tags as PHPDOCX will render them with 
			//an underline
			$oParent = BsDOMHelper::getParentDOMElement( $oImgElement );
			if( strtoupper( $oParent->nodeName ) !== 'A' ) continue;
			BsDOMHelper::insertAfter($oImgElement, $oParent);
			$oParent->parentNode->removeChild($oParent);
		}
		
		//PHPDOCX needs <p style="page-break-after" /> when using strictWordStyles and not interpreting CSS
		$oPageBreaks = $oDOMXPath->query( "//*[contains(@class, 'bs-universalexport-pagebreak')]" );
		$aPageBreaks = array(); //"non-live" list
		foreach( $oPageBreaks as $oPageBreak ) {
			$aPageBreaks[] = $oPageBreak;
		}
		foreach( $aPageBreaks as $oPageBreak ) {
			$oNewPB = $oPageDOM->createElement('p');
			//TODO: Maybe better set attribute on next DOMElement sibling of 
			//$oPageBreak and afterwards remove $oPageBreak. BUT: property may
			//only be evaluated on <p> tag!?
			$oNewPB->setAttribute( 'style', 'page-break-before:always' );
			$oPageBreak->parentNode->replaceChild($oNewPB, $oPageBreak);
		}
		
		//To avoid PHPDOCX from formatting every headline in BOLD
		//we have to and an explicit font-weight styling
		$oHeadingSpans = $oDOMXPath->query( "//*[contains(@class, 'mw-headline')]" );
		$aHeadingSpans = array(); //"non-live" list
		foreach( $oHeadingSpans as $oHeadingSpan ) {
			$aHeadingSpans[] = $oHeadingSpan;
		}
		foreach( $aHeadingSpans as $oHeadingSpan ) {
			$oHeadingSpan->setAttribute('style', 'font-weight:normal');
		}
		
		//There are some MediaWiki stylings we want to make available for
		//Word formatting
		$oMediaWikiClasses = $oDOMXPath->query( "//*[contains(@class, 'box')]" ); //TODO: may match "some-box" or "allboxes"
		foreach( $oMediaWikiClasses as $oMWClass ) {
			$sClasses = $oMWClass->getAttribute( 'class' ); //TODO: May contain more than one class!
			$aClasses = explode( ' ', $sClasses );
			
			//Flatten UL/OLs
			//TODO: Recursive?
			$aChildNodes = array();
			foreach( $oMWClass->childNodes as $oChild ) {
				$aChildNodes[] = $oChild;
			}
			
			foreach( $aChildNodes as $oList ) {
				if( $oList instanceof DOMElement == false ) continue;
				if( !in_array( strtoupper( $oList->nodeName ), array( 'UL', 'OL' ) ) ) continue;

				$oLIs = $oList->getElementsByTagName('li');
				foreach( $oLIs as $oLI ) {
					$oP = $oPageDOM->createElement('p');
					$oList->parentNode->insertBefore($oP, $oList);
					foreach( $oLI->childNodes as $oContentNode ) {
						$oP->appendChild($oContentNode);
					}
				}
				$oList->parentNode->removeChild( $oList );
			}
			
			BsDOMHelper::addClassesRecursive($oMWClass, $aClasses);
		}
	}
}
