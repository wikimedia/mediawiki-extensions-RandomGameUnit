<?php
/**
 * RandomGameUnit extension - displays a randomly chosen picture game, poll or
 * a quiz
 *
 * @file
 * @ingroup Extensions
 * @author Aaron Wright <aaron.wright@gmail.com>
 * @author David Pean <david.pean@gmail.com>
 * @author Jack Phoenix <jack@countervandalism.net>
 * @copyright Copyright © 2009-2016 Jack Phoenix <jack@countervandalism.net>
 * @link https://www.mediawiki.org/wiki/Extension:RandomGameUnit Documentation
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 */

if ( function_exists( 'wfLoadExtension' ) ) {
	wfLoadExtension( 'RandomGameUnit' );
	$wgMessagesDirs['RandomGameUnit'] =  __DIR__ . '/i18n';
	wfWarn(
		'Deprecated PHP entry point used for RandomGameUnit extension. Please use wfLoadExtension instead, ' .
		'see https://www.mediawiki.org/wiki/Extension_registration for more details.'
	);
	return;
} else {
	die( 'This version of the RandomGameUnit extension requires MediaWiki 1.25+' );
}