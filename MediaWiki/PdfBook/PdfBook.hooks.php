<?php

class PdfBookHooks {

	public static function onRegistration() {
		global $wgLogTypes, $wgLogNames, $wgLogHeaders, $wgLogActions;
		$wgLogTypes[]             = 'pdf';
		$wgLogNames  ['pdf']      = 'pdflogpage';
		$wgLogHeaders['pdf']      = 'pdflogpagetext';
		$wgLogActions['pdf/book'] = 'pdflogentry';
	}

	/**
	 * Perform the export operation
	 */
	public static function onUnknownAction( $action, $article ) {
		global $wgOut, $wgUser, $wgParser, $wgRequest, $wgAjaxComments, $wgPdfBookDownload;
		global $wgServer, $wgArticlePath, $wgScriptPath, $wgUploadPath, $wgUploadDirectory, $wgScript;

		if( $action == 'pdfbook' ) {

			// Create a cache filename from the query-string parameters
			$cache = $wgUploadDirectory . '/pdf-book-cache' . md5( json_encode( $_GET ) );

			$title = $article->getTitle();
			$book = $title->getText();
			$opt = ParserOptions::newFromUser( $wgUser );

			// Log the export
			$msg = wfMessage( 'pdfbook-log', $wgUser->getUserPage()->getPrefixedText() )->text();
			$log = new LogPage( 'pdf', false );
			$log->addEntry( 'book', $article->getTitle(), $msg );

			// Initialise PDF variables
			$format   = $wgRequest->getText( 'format' );
			$notitle  = $wgRequest->getText( 'notitle' );
			$comments = $wgAjaxComments ? $wgRequest->getText( 'comments' ) : '';
			$layout   = $format == 'single' ? '--webpage' : '--firstpage toc';
			$charset  = self::setProperty( 'Charset',     'iso-8859-1' );
			$left     = self::setProperty( 'LeftMargin',  '1cm' );
			$right    = self::setProperty( 'RightMargin', '1cm' );
			$top      = self::setProperty( 'TopMargin',   '1cm' );
			$bottom   = self::setProperty( 'BottomMargin','1cm' );
			$font     = self::setProperty( 'Font',        'Arial' );
			$size     = self::setProperty( 'FontSize',    '8' );
			$ls       = self::setProperty( 'LineSpacing', 1 );
			$linkcol  = self::setProperty( 'LinkColour',  '217A28' );
			$levels   = self::setProperty( 'TocLevels',   '2' );
			$exclude  = self::setProperty( 'Exclude',     array() );
			$width    = self::setProperty( 'Width',       '' );
			$options  = self::setProperty( 'Options',     '' );
			$width    = $width ? "--browserwidth $width" : '';
			if( !is_array( $exclude ) ) $exclude = split( '\\s*,\\s*', $exclude );
	 
			// If the file doesn't exist, render the content now
			if( !file_exists( $cache ) ) {

				// Select articles from members if a category or links in content if not
				if( $format == 'single' ) $articles = array( $title );
				else {
					$articles = array();
					if( $title->getNamespace() == NS_CATEGORY ) {
						$db     = wfGetDB( DB_SLAVE );
						$cat    = $db->addQuotes( $title->getDBkey() );
						$result = $db->select(
							'categorylinks',
							'cl_from',
							"cl_to = $cat",
							'PdfBook',
							array( 'ORDER BY' => 'cl_sortkey' )
						);
						if( $result instanceof ResultWrapper ) $result = $result->result;
						while ( $row = $db->fetchRow( $result ) ) $articles[] = Title::newFromID( $row[0] );
					}
					else {
						$text = $article->getPage()->getContent()->getNativeData();
						$text = $wgParser->preprocess( $text, $title, $opt );
						if( preg_match_all( "/^\\*\\s*\\[{2}\\s*([^\\|\\]]+)\\s*.*?\\]{2}/m", $text, $links ) )
							foreach( $links[1] as $link ) $articles[] = Title::newFromText( $link );
					}
				}

				// Format the article(s) as a single HTML document with absolute URL's
				$html = '';
				$wgArticlePath = $wgServer.$wgArticlePath;
				$wgPdfBookTab  = false;
				$wgScriptPath  = $wgServer.$wgScriptPath;
				$wgUploadPath  = $wgServer.$wgUploadPath;
				$wgScript      = $wgServer.$wgScript;
				foreach( $articles as $title ) {
					$ttext = $title->getPrefixedText();
					if( !in_array( $ttext, $exclude ) ) {
						$article = new Article( $title );
						$text    = $article->getPage()->getContent()->getNativeData();
						$text    = preg_replace( "/<!--([^@]+?)-->/s", "@@" . "@@$1@@" . "@@", $text ); # preserve HTML comments
						if( $format != 'single' ) $text .= "__NOTOC__";
						$opt->setEditSection( false );    # remove section-edit links
						$out     = $wgParser->parse( $text, $title, $opt, true, true );
						$text    = $out->getText();
						$text    = preg_replace( "|(<img[^>]+?src=\")(/.+?>)|", "$1$wgServer$2", $text );      # make image urls absolute
						$text    = preg_replace( "|<div\s*class=['\"]?noprint[\"']?>.+?</div>|s", "", $text ); # non-printable areas
						$text    = preg_replace( "|@{4}([^@]+?)@{4}|s", "<!--$1-->", $text );                  # HTML comments hack
						$ttext   = basename( $ttext );
						$h1      = $notitle ? "" : "<center><h1>$ttext</h1></center>";

						// Add comments if selected and AjaxComments is installed
						if( $comments ) {
							$comments = $wgAjaxComments->onUnknownAction( 'ajaxcommentsinternal', $article );
						}

						$html .= utf8_decode( "$h1$text\n$comments" );
					}
				}

				// Build the cache file
				if( $format == 'html' ) file_put_contents( $cache, $html );
				else {

					// Write the HTML to a tmp file
					if( !is_dir( $wgUploadDirectory ) ) mkdir( $wgUploadDirectory );
					$file = "$wgUploadDirectory/" . uniqid( 'pdf-book' );
					file_put_contents( $file, $html );

					$footer = $format == 'single' ? "..." : ".1.";
					$toc    = $format == 'single' ? "" : " --toclevels $levels";

					// Send the file to the client via htmldoc converter
					$cmd  = "--left $left --right $right --top $top --bottom $bottom";
					$cmd .= " --header ... --footer $footer --headfootsize 8 --quiet --jpeg --color";
					$cmd .= " --bodyfont $font --fontsize $size --fontspacing $ls --linkstyle plain --linkcolor $linkcol";
					$cmd .= "$toc --no-title --format pdf14 --numbered $layout $width";
					$cmd  = "htmldoc -t pdf --charset $charset $options $cmd \"$file\"";
					putenv( "HTMLDOC_NOCGI=1" );
					shell_exec( "$cmd > \"$cache\"" );
					@unlink( $file );
				}
			}

			// Output the cache file
			$wgOut->disable();
			if( $format == 'html' ) {
				header( "Content-Type: text/html" );
				header( "Content-Disposition: attachment; filename=\"$book.html\"" );
			} else {
				header( "Content-Type: application/pdf" );
				if( $wgPdfBookDownload ) header( "Content-Disposition: attachment; filename=\"$book.pdf\"" );
				else header( "Content-Disposition: inline; filename=\"$book.pdf\"" );
			}
			readfile( $cache );
			return false;
		}
		return true;
	}


	/**
	 * Return a property for htmldoc using global, request or passed default
	 */
	private static function setProperty( $name, $default ) {
		global $wgRequest;
		if( $wgRequest->getText( "pdf$name" ) ) return $wgRequest->getText( "pdf$name" );
		if( $wgRequest->getText( "amp;pdf$name" ) ) return $wgRequest->getText( "amp;pdf$name" ); // hack to handle ampersand entities in URL
		if( isset( $GLOBALS["wgPdfBook$name"] ) ) return $GLOBALS["wgPdfBook$name"];
		return $default;
	}


	/**
	 * Add PDF to actions tabs in MonoBook based skins
	 */
	public static function onSkinTemplateTabs( $skin, &$actions) {
		global $wgPdfBookTab, $wgUser;
		if( $wgPdfBookTab && $wgUser->isLoggedIn() ) {
			$actions['pdfbook'] = array(
				'class' => false,
				'text' => wfMessage( 'pdfbook-action' )->text(),
				'href' => self::actionLink( $skin )
			);
		}
		return true;
	}


	/**
	 * Add PDF to actions tabs in vector based skins
	 */
	public static function onSkinTemplateNavigation( $skin, &$actions ) {
		global $wgPdfBookTab, $wgUser;
		if( $wgPdfBookTab && $wgUser->isLoggedIn() ) {
			$actions['views']['pdfbook'] = array(
				'class' => false,
				'text' => wfMessage( 'pdfbook-action' )->text(),
				'href' => self::actionLink( $skin )
			);
		}
		return true;
	}
	
	/**
	 * Get the URL for the action link
	 */
	public static function actionLink( $skin ) {
		$qs = 'action=pdfbook&format=single';
		foreach( $_REQUEST as $k => $v ) if( $k != 'title' ) $qs .= "&$k=$v";
		return $skin->getTitle()->getLocalURL( $qs );
	}
}
