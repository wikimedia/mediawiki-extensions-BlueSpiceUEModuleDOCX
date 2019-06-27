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
 * @license    http://www.gnu.org/copyleft/gpl.html GPL-3.0-only
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
		\Hooks::run( 'BSUEModuleDOCXbeforeGetPage', array( &$aParams ) );
		
		$oTitle = Title::newFromID($aParams['article-id']);
		if( $oTitle == null ){
			$oTitle = Title::newFromText($aParams['title']);
		}
		
		$oPCP = new BsPageContentProvider();
		$oPageDOM = $oPCP->getDOMDocumentContentFor( 
			$oTitle, 
			$aParams + array( 'follow-redirects' => true )
		); // TODO RBV (06.12.11 17:09): Follow Redirect... setting or default?

		//Cleanup DOM
		self::cleanUpDOM( $oTitle, $oPageDOM, $aParams );
		
		$oDOMXPath = new DOMXPath( $oPageDOM );
		$oFirstHeading = $oDOMXPath->query( "//*[contains(@class, 'firstHeading')]" )->item(0);
		$oBodyContent  = $oDOMXPath->query( "//*[contains(@class, 'bodyContent')]" )->item(0);

		if( isset($aParams['display-title'] ) ) {
			$oFirstHeading->nodeValue = $aParams['display-title'];
		}
		
		$aPage = array(
			'dom' => $oPageDOM,
			'firstheading-element' => $oFirstHeading,
			'bodycontent-element'  => $oBodyContent,
		);
		
		\Hooks::run( 'BSUEModuleDOCXgetPage', array( $oTitle, &$aPage, &$aParams, $oDOMXPath ) );
		return $aPage;
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
		\Hooks::run( 'BSUEModuleDOCXcleanUpDOM', array( $oTitle, $oPageDOM, &$aParams, $oDOMXPath, &$aClassesToRemove ) );

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
