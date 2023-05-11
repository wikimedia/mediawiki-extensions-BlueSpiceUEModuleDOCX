<?php
/**
 * DOCXServlet.
 *
 * Part of BlueSpice MediaWiki
 *
 * @author     Robert Vogel <vogel@hallowelt.com>
 * @version    $Id: DOCXServlet.php 8919 2013-03-15 15:33:14Z rvogel $
 * @package    BlueSpice_Extensions
 * @subpackage UEModuleDOCX
 * @copyright  Copyright (C) 2016 Hallo Welt! GmbH, All rights reserved.
 * @license    http://www.gnu.org/copyleft/gpl.html GPL-3.0-only
 * @filesource
 */

use GuzzleHttp\Client;
use MediaWiki\MediaWikiServices;

/**
 * UniversalExport DOCXServlet class.
 * @package BlueSpice_Extensions
 * @subpackage UEModuleDOCX
 */
class DOCXServlet {

	/**
	 * Gets a DOMDocument,
	 * searches it for files,
	 * uploads files and markus to webservice and generated DOCX.
	 * @param DOMDocument &$HtmlDOM The source markup
	 * @param string $DOCXTemplatePath
	 * @return string The resulting DOCX as bytes
	 * @throws ConfigException
	 * @throws FatalError
	 * @throws MWException
	 */
	public function createDOCX( &$HtmlDOM, $DOCXTemplatePath ) {
		if ( !file_exists( $DOCXTemplatePath ) ) {
			throw new MWException( $DOCXTemplatePath );
			// TODO: better place?
		}

		$this->findFiles( $HtmlDOM );
		$this->uploadFiles();

		// HINT: http://www.php.net/manual/en/class.domdocument.php#96055
		// But: Formated Output is evil because is will destroy formatting in <pre> Tags!
		$HtmlDOMXML = $HtmlDOM->saveXML( $HtmlDOM->documentElement );

		// Save temporary
		$status = BsFileSystemHelper::ensureCacheDirectory( 'UEModuleDOCX' );
		if ( !$status->isOK() ) {
			throw new MWException( $status->getMessage() );
		}
		$tmpHtmlFile = BS_DATA_DIR . '/UEModuleDOCX/' . $this->params['document-token'] . '.html';
		$tmpDOCXFile = BS_DATA_DIR . '/UEModuleDOCX/' . $this->params['document-token'] . '.docx';
		file_put_contents( $tmpHtmlFile, $HtmlDOMXML );

		$config = MediaWikiServices::getInstance()->getConfigFactory()
			->makeConfig( 'bsg' );

		$postData = [
			'fileType'      => 'template',
			'documentToken' => $this->params['document-token'],
			'templateFile'  => $DOCXTemplatePath,
			'templateFile_name'  => basename( $DOCXTemplatePath ),
			'wikiId'        => WikiMap::getCurrentWikiId(),
			'secret'        => $config->get(
				'UEModuleDOCXDOCXServiceSecret'
			),
		];

		if ( $config->get( 'TestMode' ) ) {
			$postData['debug'] = "true";
		}

		MediaWikiServices::getInstance()->getHookContainer()->run(
			'BSUEModuleDOCXCreateDOCXBeforeSend',
			[
				$this,
				&$postData,
				$HtmlDOM
			]
		);

		$multipartPostData = $this->convertToMultipart( $postData );
		unset( $postData['templateFile'] );
		unset( $postData['fileType'] );
		// Upload HTML source
		$this->request(
			$this->params['backend-url'] . '/UploadAsset/', $multipartPostData
		);

		// Now do the rendering
		$postData['WIKICONTENT'] = $HtmlDOMXML;

		$docxByteArray = $this->request(
			$this->params['backend-url'] . '/RenderDOCX/', [ 'body' => $postData ], [
				'Content-Type' => 'application/x-www-form-urlencoded'
			]
		);

		if ( $docxByteArray == false ) {
			wfDebugLog(
				'BS::UEModuleDOCX',
				'DOCXServlet::createDOCX: Failed creating "' . $this->params['document-token'] . '"'
			);
			throw new MWException(
				'DOCXServlet::createDOCX: Failed creating "'
				. $this->params['document-token']
				. '"'
			);
		}

		file_put_contents( $tmpDOCXFile, $docxByteArray );

		// Remove temporary file
		if ( !$config->get( 'TestMode' ) ) {
			unlink( $tmpHtmlFile );
			unlink( $tmpDOCXFile );
		}

		return $docxByteArray;
	}

	/**
	 * Uploads all files found in the markup by the "findFiles" method.
	 */
	protected function uploadFiles() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()
			->makeConfig( 'bsg' );

		foreach ( $this->filesList as $type => $filesList ) {

			// Backwards compatibility to old inconsitent DOCXTemplates
			// (having "STYLESHEET" as type but linnking to "stylesheets")
			// TODO: Make conditional?
			if ( $type == 'IMAGE' ) {
				$type = 'images';
			}
			if ( $type == 'STYLESHEET' ) {
				$type = 'stylesheets';
			}

			$postData = [
				'multipart' => [
					[
						'name' => 'fileType',
						'contents' => $type,
					],
					[
						'name' => 'documentToken',
						'contents' => $this->params['document-token'],
					],
					[
						'name' => 'wikiId',
						'contents' => WikiMap::getCurrentWikiId(),
					],
					[
						'name' => 'secret',
						'contents' => $config->get( 'UEModuleDOCXDOCXServiceSecret' ),
					],
				],
			];

			$errors = [];
			foreach ( $filesList as $fileName => $sFilePath ) {
				if ( file_exists( $sFilePath ) == false ) {
					$errors[] = $sFilePath;
					continue;
				}
				// 'myfile.css' => {file_contents}
				// 'myfile.css_name' => 'myfile.css'
				$postData['multipart'][] = [
					'name' => $fileName,
					'contents' => file_get_contents( $sFilePath ),
					'filename' => $fileName
				];
				$postData['multipart'][] = [
					'name' => "{$fileName}_name",
					'contents' => $fileName
				];
			}

			if ( !empty( $errors ) ) {
				wfDebugLog(
					'BS::UEModuleDOCX',
					'DOCXServlet::uploadFiles: Error trying to fetch files:' . "\n" . var_export( $errors, true )
				);
			}

			MediaWikiServices::getInstance()->getHookContainer()->run(
				'BSUEModuleDOCXUploadFilesBeforeSend',
				[
					$this,
					&$postData,
					$type
				]
			);

			$this->doFilesUpload( $postData, $errors );

		}
	}

	/**
	 * @param array $aPostData
	 * @param array|null $aErrors
	 * @return array|null
	 */
	protected function doFilesUpload( $aPostData, $aErrors = [] ) {
		$sType = null;
		foreach ( $aPostData['multipart'] as $aFile ) {
			if ( $aFile['name'] === 'fileType' ) {
				$sType = $aFile['contents'];
				break;
			}
		}

		if ( !empty( $aErrors ) ) {
			wfDebugLog(
				'BS::UEModulePDF',
				'BsPDFServlet::uploadFiles: Error trying to fetch files:' . "\n" . var_export( $aErrors, true )
			);
		}

		$response = $this->request(
			$this->params['backend-url'] . '/UploadAsset/', $aPostData
		);

		if ( $response != false ) {
			wfDebugLog(
				'BS::UEModuleDOCX',
				'DOCXServlet::uploadFiles: Successfully added "' . $sType . '"'
			);
			wfDebugLog(
				'BS::UEModuleDOCX',
				$response
			);
		} else {
			wfDebugLog(
				'BS::UEModuleDOCX',
				'DOCXServlet::uploadFiles: Failed adding "' . $sType . '"'
			);
		}

		return null;
	}

	/**
	 * @param array $postData in form_params format
	 *
	 * @return array
	 */
	private function convertToMultipart( $postData ): array {
		$multipart = [];
		foreach ( $postData as $postItemKey => $postItem ) {
			if ( $postItemKey === 'multipart' ) {
				return $postData;
			}
			if ( $postItemKey === 'sourceHtmlFile' ) {
				$multipart[] = [
					'name' => $postItemKey,
					'filename' => basename( $postItem ),
					'contents' => file_get_contents( $postItem ),
				];
				continue;
			}
			$multipart[] = [ 'name' => $postItemKey, 'contents' => $postItem ];
		}
		return [ 'multipart' => $multipart ];
	}

	/**
	 * @param string $url
	 * @param array $options
	 * @param array|null $headers
	 *
	 * @return string
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 */
	private function request( string $url, array $options, ?array $headers = [] ): string {
		$config = array_merge( [
			'headers' => $headers,
			'timeout' => 120
		], $GLOBALS['bsgUEModulePDFRequestOptions'] );
		$config['headers']['User-Agent'] = MediaWikiServices::getInstance()->getHttpRequestFactory()->getUserAgent();

		// Create client manually, since calling `createGuzzleClient` on httpFactory will throw a fatal
		// complaining `$this->options` is NULL. Which should not happen, but I cannot find why it happens
		$client = new Client( $config );
		$response = $client->request( 'POST', $url, $options );
		return $response->getBody()->getContents();
	}

	/**
	 *
	 * @var array
	 */
	protected $params = [];

	/**
	 *
	 * @var array
	 */
	protected $filesList = [];

	/**
	 * The contructor method forthis class.
	 * @param array &$params The params have to contain the key
	 * 'backend-url', with a valid URL to the webservice. They can
	 * contain a key 'soap-connection-options' for the SoapClient constructor
	 * and a key 'resources' with al list of files to upload.
	 * @throws UnexpectedValueException If 'backend-url' is not set or the Webservice is not available.
	 */
	public function __construct( &$params ) {
		$this->params = $params;
		// $this->filesList =  $params['resources'];

		if ( empty( $this->params['backend-url'] ) ) {
			throw new UnexpectedValueException( 'backend-url-not-set' );
		}

		if ( !BsConnectionHelper::urlExists( $this->params['backend-url'] ) ) {
			throw new UnexpectedValueException( 'backend-url-not-valid' );
		}

		// If a slash is last char, remove it.
		if ( substr( $this->params['backend-url'], -1 ) == '/' ) {
			$this->params['backend-url'] = substr( $this->params['backend-url'], 0, -1 );
		}
	}

	/**
	 * Searches the DOM for <img>-Tags and <a> Tags with class 'internal',
	 * resolves the local filesystem path and adds it to $filesList array.
	 * @param DOMDocument &$html The markup to be searched.
	 * @return bool Well, always true.
	 */
	protected function findFiles( &$html ) {
		$repoGroup = MediaWikiServices::getInstance()->getRepoGroup();
		// Find all images
		$imageElements = $html->getElementsByTagName( 'img' );
		foreach ( $imageElements as $imageElement ) {
			$srcUrl      = urldecode( $imageElement->getAttribute( 'src' ) );
			$srcFilename = basename( $srcUrl );

			$isThumb = UploadBase::isThumbName( $srcFilename );
			$tmpFilename = $srcFilename;
			if ( $isThumb ) {
				// HINT: Thumbname-to-filename-conversion taken from includes/Upload/UploadBase.php
				// Check for filenames like 50px- or 180px-, these are mostly thumbnails
				$tmpFilename = substr( $tmpFilename, strpos( $tmpFilename, '-' ) + 1 );
			}
			$fileTitle = Title::newFromText( $tmpFilename, NS_FILE );
			$image = $repoGroup->findFile( $fileTitle );

			// TODO: This is a quickfix for MW 1.19+ --> find better solution
			if ( $image instanceof File && $image->exists() ) {
				$fileRepoLocalRef = $image->getRepo()->getLocalReference( $image->getPath() );
				if ( $fileRepoLocalRef !== null ) {
					$absoluteFileSystemPath = $fileRepoLocalRef->getPath();
				}
				$srcFilename = $image->getName();
			} else {
				$absoluteFileSystemPath = $this->getFileSystemPath( $srcUrl );
			}
			// TODO RBV (05.04.12 11:48): Check if urlencode has side effects
			$imageElement->setAttribute( 'src', 'images/' . urlencode( $srcFilename ) );
			$fileName = $srcFilename;
			MediaWikiServices::getInstance()->getHookContainer()->run(
				'BSUEModuleDOCXFindFiles',
				[
					$this,
					$imageElement,
					$absoluteFileSystemPath,
					$fileName,
					'images'
				]
			);
			MediaWikiServices::getInstance()->getHookContainer()->run(
				'BSUEModuleDOCXWebserviceFindFiles',
				[
					$this,
					$imageElement,
					$absoluteFileSystemPath,
					$fileName,
					'images'
				]
			);
			$this->filesList['images'][$fileName] = $absoluteFileSystemPath;
		}

		$DOMXPath = new DOMXPath( $html );

		MediaWikiServices::getInstance()->getHookContainer()->run(
			'BSUEModuleDOCXAfterFindFiles',
			[
				$this,
				$html,
				&$this->filesList,
				$this->params,
				$DOMXPath
			]
		);
		return true;
	}

	// <editor-fold desc="Helper Methods" defaultstate="collapsed">

	/**
	 * This helper method resolves the local file system path of a found file
	 * @param string $url
	 * @return string The local file system path
	 */
	public function getFileSystemPath( $url ) {
		if ( substr( $url, 0, 1 ) !== '/' || strpos( $url, $this->params['webroot-filesystempath'] ) === 0 ) {
			// not relative to webroot or absolute filesystempath
			return $url;
		}

		$scriptUrlDir = dirname( $_SERVER['SCRIPT_NAME'] );
		$scriptFSDir  = dirname( $_SERVER['SCRIPT_FILENAME'] );
		if ( strpos( $scriptFSDir, $scriptUrlDir ) == 0 ) {
			// detect virtual path (webserver setting)
			$url = '/' . substr( $url, strlen( $scriptUrlDir ) );
		}

		$newUrl = $this->params['webroot-filesystempath'] . $url;
		// TODO RBV (08.02.11 15:56): What about $wgUploadDirectory?
		return $newUrl;
	}

	// </editor-fold>
}
