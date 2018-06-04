<?php
/**
 * BsDOCXServlet.
 *
 * Part of BlueSpice MediaWiki
 *
 * @author     Robert Vogel <vogel@hallowelt.com>
 * @version    $Id: DOCXServlet.class.php 8919 2013-03-15 15:33:14Z rvogel $
 * @package    BlueSpice_Extensions
 * @subpackage UEModuleDOCX
 * @copyright  Copyright (C) 2016 Hallo Welt! GmbH, All rights reserved.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU Public License v3
 * @filesource
 */

/**
 * UniversalExport BsDOCXServlet class.
 * @package BlueSpice_Extensions
 * @subpackage UEModuleDOCX
 */
class BsDOCXServlet {

	/**
	 * Gets a DOMDocument, searches it for files, uploads files and markus to webservice and generated DOCX.
	 * @param DOMDocument $oHtmlDOM The source markup
	 * @return string The resulting DOCX as bytes
	 */
	public function createDOCX( &$oHtmlDOM, $sDOCXTemplatePath ) {
		if( !file_exists( $sDOCXTemplatePath ) ) {
			throw new MWException( $sDOCXTemplatePath ); //TODO: better place?
		}

		$this->findFiles( $oHtmlDOM );
		$this->uploadFiles();

		//HINT: http://www.php.net/manual/en/class.domdocument.php#96055
		//But: Formated Output is evil because is will destroy formatting in <pre> Tags!
		$sHtmlDOM = $oHtmlDOM->saveXML( $oHtmlDOM->documentElement );

		//Save temporary
		$sTmpHtmlFile = BSDATADIR.'/UEModuleDOCX/'.$this->aParams['document-token'].'.html';
		$sTmpDOCXFile = BSDATADIR.'/UEModuleDOCX/'.$this->aParams['document-token'].'.docx';
		file_put_contents( $sTmpHtmlFile, $sHtmlDOM );

		$config = \BlueSpice\Services::getInstance()->getConfigFactory()
			->makeConfig( 'bsg' );

		$aOptions = array(
			'method'          => 'POST',
			'timeout'         => 120,
			'followRedirects' => true,
			'sslVerifyHost'   => false,
			'sslVerifyCert'   => false,
			'postData' => array(
				'fileType'      => 'template',
				'documentToken' => $this->aParams['document-token'],
				'templateFile'  => class_exists( 'CURLFile' ) ? new CURLFile( $sDOCXTemplatePath ) : '@'.$sDOCXTemplatePath,
				'wikiId'        => wfWikiID(),
				'secret'        => $config->get(
					'UEModuleDOCXDOCXServiceSecret'
				),
			)
		);

		if( $config->get( 'TestMode' ) ) {
			$aOptions['postData']['debug'] = "true";
		}

		wfRunHooks( 'BSUEModuleDOCXCreateDOCXBeforeSend', array( $this, &$aOptions, $oHtmlDOM ) );

		$vHttpEngine = Http::$httpEngine;
		Http::$httpEngine = 'curl';
		//HINT: http://www.php.net/manual/en/function.curl-setopt.php#refsect1-function.curl-setopt-notes
		$oRequest = MWHttpRequest::factory(
				//Tailing slash is important because otherwise Webserver will send
				//"Moved Permanently" and cURL seems to loose POST data when
				//following redirect
				wfExpandUrl( $this->aParams['backend-url'].'/UploadAsset/' ),
				$aOptions
		);
		$oStatus = $oRequest->execute();
		if( !$oStatus->isOK() ) {
			throw new MWException( $oStatus->getMessage() );
		}

		//Now do the rendering
		//We re-send the paramters but this time without the file.
		unset( $aOptions['postData']['templateFile'] );
		unset( $aOptions['postData']['fileType'] );

		$aOptions['postData']['WIKICONTENT'] = $sHtmlDOM;

		$oRequest = MWHttpRequest::factory(
			wfExpandUrl( $this->aParams['backend-url'].'/RenderDOCX/' ),
			$aOptions
		);
		$oStatus = $oRequest->execute();
		if( !$oStatus->isOK() ) {
			throw new MWException( $oStatus->getMessage() );
		}
		$vPdfByteArray = $oRequest->getContent();
		Http::$httpEngine = $vHttpEngine;
		if( $vPdfByteArray == false ) {
			wfDebugLog(
				'BS::UEModuleDOCX',
				'BsDOCXServlet::createDOCX: Failed creating "'.$this->aParams['document-token'].'"'
			);
			throw new MWException( 'BsDOCXServlet::createDOCX: Failed creating "'.$this->aParams['document-token'].'"' );
		}

		file_put_contents( $sTmpDOCXFile, $vPdfByteArray );

		//Remove temporary file
		if( !$config->get( 'TestMode' ) ) {
			unlink( $sTmpHtmlFile );
			unlink( $sTmpDOCXFile );
		}

		return $vPdfByteArray;
	}

	/**
	 * Uploads all files found in the markup by the "findFiles" method.
	 */
	protected function uploadFiles() {
		$config = \BlueSpice\Services::getInstance()->getConfigFactory()
			->makeConfig( 'bsg' );

		foreach( $this->aFiles as $sType => $aFiles ) {

			//Backwards compatibility to old inconsitent DOCXTemplates (having "STYLESHEET" as type but linnking to "stylesheets")
			//TODO: Make conditional?
			if( $sType == 'IMAGE' )      $sType = 'images';
			if( $sType == 'STYLESHEET' ) $sType = 'stylesheets';

			$aPostData = array(
				'fileType'      => $sType,
				'documentToken' => $this->aParams['document-token'],
				'wikiId'        => wfWikiID(),
				'secret'        => $config->get(
					'UEModuleDOCXDOCXServiceSecret'
				)
			);

			$aErrors = array();
			$iCounter = 0;
			foreach( $aFiles as $sFileName => $sFilePath ) {
				if( file_exists( $sFilePath) == false ) {
					$aErrors[] = $sFilePath;
					continue;
				}
				$aPostData['file'.$iCounter++] = class_exists( 'CURLFile' ) ? new CURLFile( $sFilePath ) : '@'.$sFilePath;
			}

			if( !empty( $aErrors ) ) {
				wfDebugLog(
					'BS::UEModuleDOCX',
					'BsDOCXServlet::uploadFiles: Error trying to fetch files:'."\n". var_export( $aErrors, true )
				);
			}

			wfRunHooks( 'BSUEModuleDOCXUploadFilesBeforeSend', array( $this, &$aPostData, $sType ) );

			$vHttpEngine = Http::$httpEngine;
			Http::$httpEngine = 'curl';
			$sResponse = Http::post(
				$this->aParams['backend-url'].'/UploadAsset/',
				array(
					'timeout' => 120,
					'followRedirects' => true,
					'sslVerifyHost' => false,
					'sslVerifyCert' => false,
					'postData' => $aPostData
				)
			);
			Http::$httpEngine = $vHttpEngine;

			if( $sResponse != false ) {
				wfDebugLog(
					'BS::UEModuleDOCX',
					'BsDOCXServlet::uploadFiles: Successfully added "'.$sType.'"'
				);
				wfDebugLog(
					'BS::UEModuleDOCX',
					$sResponse
				);
			}
			else {
				wfDebugLog(
					'BS::UEModuleDOCX',
					'BsDOCXServlet::uploadFiles: Failed adding "'.$sType.'"'
				);
			}
		}
	}

	/**
	 *
	 * @var array
	 */
	protected $aParams = array();

	/**
	 *
	 * @var array
	 */
	protected $aFiles  = array();

	/**
	 * The contructor method forthis class.
	 * @param array $aParams The params have to contain the key
	 * 'backend-url', with a valid URL to the webservice. They can
	 * contain a key 'soap-connection-options' for the SoapClient constructor
	 * and a key 'resources' with al list of files to upload.
	 * @throws UnexpectedValueException If 'backend-url' is not set or the Webservice is not available.
	 */
	public function __construct( &$aParams ) {

		$this->aParams = $aParams;
		//$this->aFiles =  $aParams['resources'];

		if ( empty( $this->aParams['backend-url'] ) ) {
			throw new UnexpectedValueException( 'backend-url-not-set' );
		}

		if( !BsConnectionHelper::urlExists( $this->aParams['backend-url'] ) ) {
			throw new UnexpectedValueException( 'backend-url-not-valid' );
		}

		//If a slash is last char, remove it.
		if( substr($this->aParams['backend-url'], -1) == '/' ) {
			$this->aParams['backend-url'] = substr($this->aParams['backend-url'], 0, -1);
		}
	}

	/**
	 * Searches the DOM for <img>-Tags and <a> Tags with class 'internal',
	 * resolves the local filesystem path and adds it to $aFiles array.
	 * @param DOMDocument $oHtml The markup to be searched.
	 * @return boolean Well, always true.
	 */
	protected function findFiles( &$oHtml ) {
		//Find all images
		$oImageElements = $oHtml->getElementsByTagName( 'img' );
		foreach( $oImageElements as $oImageElement ) {
			$sSrcUrl      = urldecode( $oImageElement->getAttribute( 'src' ) );
			$sSrcFilename = basename( $sSrcUrl );

			$bIsThumb = UploadBase::isThumbName($sSrcFilename);
			$sTmpFilename = $sSrcFilename;
			if( $bIsThumb ) {
				//HINT: Thumbname-to-filename-conversion taken from includes/Upload/UploadBase.php
				//Check for filenames like 50px- or 180px-, these are mostly thumbnails
				$sTmpFilename = substr( $sTmpFilename , strpos( $sTmpFilename , '-' ) +1 );
			}
			$oFileTitle = Title::newFromText( $sTmpFilename, NS_FILE );
			$oImage = RepoGroup::singleton()->findFile( $oFileTitle );

			//TODO: This is a quickfix for MW 1.19+ --> find better solution
			global $wgVersion;
			if( $oImage instanceof File && $oImage->exists() ) {
				if ( $wgVersion < '1.18.0' ) {
					$sAbsoluteFileSystemPath = $oImage->getPath();
				}
				else {
					$oFileRepoLocalRef = $oImage->getRepo()->getLocalReference( $oImage->getPath() );
					if ( !is_null( $oFileRepoLocalRef ) ) {
						$sAbsoluteFileSystemPath = $oFileRepoLocalRef->getPath();
					}
				}
				$sSrcFilename = $oImage->getName();
			}
			else {
				$sAbsoluteFileSystemPath = $this->getFileSystemPath( $sSrcUrl );
			}
			// TODO RBV (05.04.12 11:48): Check if urlencode has side effects
			$oImageElement->setAttribute( 'src', 'images/'.urlencode($sSrcFilename) );
			$sFileName = $sSrcFilename;
			wfRunHooks( 'BSUEModuleDOCXFindFiles', array( $this, $oImageElement, $sAbsoluteFileSystemPath, $sFileName, 'images' ) );
			wfRunHooks( 'BSUEModuleDOCXWebserviceFindFiles', array( $this, $oImageElement, $sAbsoluteFileSystemPath, $sFileName, 'images' ) );
			$this->aFiles['images'][$sFileName] =  $sAbsoluteFileSystemPath;
		}

		$oDOMXPath = new DOMXPath( $oHtml );

		/*
		 * This is now in template
		//Find all CSS files
		$oLinkElements = $oHtml->getElementsByTagName( 'link' ); // TODO RBV (02.02.11 16:48): Limit to rel="stylesheet" and type="text/css"
		foreach( $oLinkElements as $oLinkElement ) {
			$sHrefUrl = $oLinkElement->getAttribute( 'href' );
			$sHrefFilename           = basename( $sHrefUrl );
			$sAbsoluteFileSystemPath = $this->getFileSystemPath( $sHrefUrl );
			$this->aFiles[ $sAbsoluteFileSystemPath ] = array( $sHrefFilename, 'STYLESHEET' );
			$oLinkElement->setAttribute( 'href', 'stylesheets/'.$sHrefFilename );
		}
		 */

		wfRunHooks( 'BSUEModuleDOCXAfterFindFiles', array( $this, $oHtml, &$this->aFiles, $this->aParams, $oDOMXPath ) );
		return true;
	}

	//<editor-fold desc="Helper Methods" defaultstate="collapsed">
	/**
	 * This helper method resolves the local file system path of a found file
	 * @param string $sUrl
	 * @return string The local file system path
	 */
	public function getFileSystemPath( $sUrl ) {
		if( $sUrl{0} !== '/' || strpos( $sUrl, $this->aParams['webroot-filesystempath'] ) === 0 ) {
			return $sUrl; //not relative to webroot or absolute filesystempath
		}

		$sScriptUrlDir = dirname( $_SERVER['SCRIPT_NAME'] );
		$sScriptFSDir  = dirname( $_SERVER['SCRIPT_FILENAME'] );
		if( strpos( $sScriptFSDir, $sScriptUrlDir) == 0 ){ //detect virtual path (webserver setting)
			$sUrl = '/'.substr( $sUrl, strlen( $sScriptUrlDir ) );
		}

		$sNewUrl = $this->aParams['webroot-filesystempath'].$sUrl; // TODO RBV (08.02.11 15:56): What about $wgUploadDirectory?
		return $sNewUrl;
	}
	//</editor-fold>
}