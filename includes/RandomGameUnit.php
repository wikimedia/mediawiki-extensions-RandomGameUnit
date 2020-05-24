<?php
/**
 * RandomGameUnit extension - displays a randomly chosen picture game, poll or
 * a quiz
 *
 * @file
 * @ingroup Extensions
 * @author Aaron Wright <aaron.wright@gmail.com>
 * @author David Pean <david.pean@gmail.com>
 * @author Jack Phoenix
 * @copyright Copyright © 2009-2018 Jack Phoenix
 * @link https://www.mediawiki.org/wiki/Extension:RandomGameUnit Documentation
 * @license GPL-2.0-or-later
 */

use MediaWiki\MediaWikiServices;

class RandomGameUnit {
	/**
	 * Set up the <randomgameunit> parser hook
	 *
	 * @param Parser &$parser Instance of Parser
	 */
	public static function registerTag( &$parser ) {
		$parser->setHook( 'randomgameunit', [ __CLASS__, 'getRandomGameUnit' ] );
	}

	public static function getRandomGameUnit( $input = '', $argsv = [], $parser = null ) {
		global $wgRandomGameDisplay;

		$random_games = [];
		$custom_fallback = '';

		if ( $wgRandomGameDisplay['random_poll'] ) {
			$random_games[] = 'poll';
		}

		if ( $wgRandomGameDisplay['random_quiz'] ) {
			$random_games[] = 'quiz';
		}

		if ( $wgRandomGameDisplay['random_picturegame'] ) {
			$random_games[] = 'picgame';
		}

		if ( !Hooks::run( 'RandomGameUnit', [ &$random_games, &$custom_fallback ] ) ) {
			wfDebug( __METHOD__ . ": RandomGameUnit hook messed up the page!\n" );
		}

		if ( count( $random_games ) == 0 ) {
			return '';
		}

		// Add CSS to the output if we can
		// This is true when RandomGameUnit is invoked as a parser hook
		// (<randomgameunit /> in wikitext) but false when this method is
		// statically called by another extension (BlogPage, LinkFilter)
		// or skin (Nimbus)
		if ( $parser instanceof Parser ) {
			$parser->getOutput()->addModuleStyles( 'ext.RandomGameUnit.css' );
		}

		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		$random_category = $random_games[array_rand( $random_games, 1 )];
		$count = 10;
		switch ( $random_category ) {
			case 'poll':
				$polls = Poll::getPollList( $count );
				if ( $polls ) {
					$random_poll = $polls[array_rand( $polls )];
					return self::displayPoll( $random_poll );
				}
				break;
			case 'quiz':
				$quiz = [];
				// Try cache
				$key = $cache->makeKey( 'quiz', 'order', 'q_id', 'count', $count );
				$data = $cache->get( $key );
				if ( $data ) {
					wfDebugLog( 'RandomGameUnit', "Got quiz list ($count) from cache" );
					$quiz = $data;
				} else {
					wfDebugLog( 'RandomGameUnit', "Got quiz list ($count) ordered by q_id from DB" );
					$dbr = wfGetDB( DB_REPLICA );
					$params['LIMIT'] = $count;
					$params['ORDER BY'] = 'q_id DESC';
					$res = $dbr->select(
						'quizgame_questions',
						[ 'q_id', 'q_text', 'q_picture' ],
						/* WHERE */[],
						__METHOD__,
						$params
					);
					foreach ( $res as $row ) {
						$quiz[] = [
							'id' => $row->q_id,
							'text' => $row->q_text,
							'image' => $row->q_picture
						];
					}
					$cache->set( $key, $quiz, 60 * 10 );
				}
				if ( is_array( $quiz ) && !empty( $quiz ) ) {
					$random_quiz = $quiz[array_rand( $quiz )];
					if ( $random_quiz ) {
						return self::displayQuiz( $random_quiz );
					}
				}
				break;
			case 'picgame':
				// Try cache
				$pics = [];
				$key = $cache->makeKey( 'picgame', 'order', 'q_id', 'count', $count );
				$data = $cache->get( $key );
				if ( $data ) {
					wfDebugLog( 'RandomGameUnit', "Got picture game list ($count) ordered by id from cache" );
					$pics = $data;
				} else {
					wfDebugLog( 'RandomGameUnit', "Got picture game list ($count) ordered by id from DB" );
					$dbr = wfGetDB( DB_REPLICA );
					$params['LIMIT'] = $count;
					$params['ORDER BY'] = 'id DESC';
					$res = $dbr->select(
						'picturegame_images',
						[ 'id', 'title', 'img1', 'img2' ],
						/* WHERE */[ 'flag <> 1' /* 1 = PictureGameHome::$FLAG_FLAGGED */ ],
						__METHOD__,
						$params
					);
					foreach ( $res as $row ) {
						$pics[] = [
							'id' => $row->id,
							'title' => $row->title,
							'img1' => $row->img1,
							'img2' => $row->img2
						];
					}
					$cache->set( $key, $pics, 60 * 10 );
				}
				if ( is_array( $pics ) && !empty( $pics ) ) {
					$random_picgame = $pics[array_rand( $pics )];
					if ( $random_picgame ) {
						return self::displayPictureGame( $random_picgame );
					}
				}

				break;
			case 'custom':
				if ( $custom_fallback ) {
					return call_user_func( $custom_fallback, $count );
				}
				break;
		}

		// Still here? That means one thing and one thing only: none of the switch()
		// cases below managed to return HTML. Return '' to prevent exposing strip markers.
		return '';
	}

	public static function displayPoll( $poll ) {
		global $wgRandomImageSize;

		// I don't see how it'd be possible that NS_POLL is undefined at this point
		// but it's better to be safe than sorry, so I added this here.
		if ( defined( 'NS_POLL' ) ) {
			$ns = NS_POLL;
		} else {
			$ns = 300;
		}

		$poll_link = Title::makeTitle( $ns, $poll['title'] );
		$output = '<div class="game-unit-container">
			<h2>' . wfMessage( 'game-unit-poll-title' )->plain() . '</h2>
			<div class="poll-unit-title">' . $poll_link->getText() . '</div>';

		if ( $poll['image'] ) {
			$poll_image_width = $wgRandomImageSize;
			$poll_image = MediaWikiServices::getInstance()->getRepoGroup()->findFile( $poll['image'] );
			$poll_image_url = $width = '';
			if ( is_object( $poll_image ) ) {
				$poll_image_url = $poll_image->createThumb( $poll_image_width );
				if ( $poll_image->getWidth() >= $poll_image_width ) {
					$width = $poll_image_width;
				} else {
					$width = $poll_image->getWidth();
				}
			}
			$poll_image_tag = '<img width="' . $width . '" alt="" src="' . $poll_image_url . '"/>';
			$output .= '<div class="poll-unit-image">' . $poll_image_tag . '</div>';
		}

		$output .= '<div class="poll-unit-choices">';
		foreach ( $poll['choices'] as $choice ) {
			$output .= '<a href="' . htmlspecialchars( $poll_link->getFullURL() ) . '" rel="nofollow">
				<input id="poll_choice" type="radio" value="10" name="poll_choice" onclick="location.href=\'' .
				htmlspecialchars( $poll_link->getFullURL() ) . '\'" /> ' . $choice['choice'] .
			'</a>';
		}
		$output .= '</div>
		</div>';

		return $output;
	}

	public static function displayQuiz( $quiz ) {
		global $wgRandomImageSize;

		$quiz_title = SpecialPage::getTitleFor( 'QuizGameHome' );
		$output = '<div class="game-unit-container">
			<h2>' . wfMessage( 'game-unit-quiz-title' )->plain() . '</h2>
			<div class="quiz-unit-title"><a href="' . htmlspecialchars( $quiz_title->getFullURL( "questionGameAction=renderPermalink&permalinkID={$quiz['id']}" ) ) . '" rel="nofollow">' . $quiz['text'] . '</a></div>';

		if ( $quiz['image'] ) {
			$quiz_image_width = $wgRandomImageSize;
			$quiz_image = MediaWikiServices::getInstance()->getRepoGroup()->findFile( $quiz['image'] );
			$quiz_image_url = $width = '';
			if ( is_object( $quiz_image ) ) {
				$quiz_image_url = $quiz_image->createThumb( $quiz_image_width );
				if ( $quiz_image->getWidth() >= $quiz_image_width ) {
					$width = $quiz_image_width;
				} else {
					$width = $quiz_image->getWidth();
				}
			}
			$quiz_image_tag = '<a href="' . htmlspecialchars( $quiz_title->getFullURL( "questionGameAction=renderPermalink&permalinkID={$quiz['id']}" ) ) . '" rel="nofollow">
			<img width="' . $width . '" alt="" src="' . $quiz_image_url . '"/></a>';
			$output .= '<div class="quiz-unit-image">' . $quiz_image_tag . '</div>';
		}

		$output .= '</div>';
		return $output;
	}

	public static function displayPictureGame( $picturegame ) {
		global $wgRandomImageSize;

		if ( !$picturegame['img1'] || !$picturegame['img2'] ) {
			return '';
		}

		$img_width = $wgRandomImageSize;
		if ( $picturegame['title'] == substr( $picturegame['title'], 0, 48 ) ) {
			$title_text = $picturegame['title'];
		} else {
			$title_text = substr( $picturegame['title'], 0, 48 ) . wfMessage( 'ellipsis' )->escaped();
		}

		$repoGroup = MediaWikiServices::getInstance()->getRepoGroup();
		$img_one = $repoGroup->findFile( $picturegame['img1'] );
		$thumb_one_url = $imgOneWidth = '';
		if ( is_object( $img_one ) ) {
			$thumb_one_url = $img_one->createThumb( $img_width );
			if ( $img_one->getWidth() >= $img_width ) {
				$imgOneWidth = $img_width;
			} else {
				$imgOneWidth = $img_one->getWidth();
			}
		}
		$imgOne = '<img width="' . $imgOneWidth . '" alt="" src="' . $thumb_one_url . '?' . time() . '"/>';

		$img_two = $repoGroup->findFile( $picturegame['img2'] );
		$thumb_two_url = $imgTwoWidth = '';
		if ( is_object( $img_two ) ) {
			$thumb_two_url = $img_two->createThumb( $img_width );
			if ( $img_two->getWidth() >= $img_width ) {
				$imgTwoWidth = $img_width;
			} else {
				$imgTwoWidth = $img_two->getWidth();
			}
		}
		$imgTwo = '<img width="' . $imgTwoWidth . '" alt="" src="' . $thumb_two_url . '?' . time() . '"/>';

		$pic_game_link = SpecialPage::getTitleFor( 'PictureGameHome' );

		# check PictureGame/PictureGameHome.body.php to see what value of $key should be
		$key = '';
		# global $wgUser;
		# $key = md5( $picturegame['id'] . md5( $wgUser->getName() ) ); // the 2nd param should be PictureGameHome::$SALT but that is a private member variable

		$output = '<div class="game-unit-container">
		<h2>' . wfMessage( 'game-unit-picturegame-title' )->plain() . '</h2>
		<div class="pg-unit-title">' . $title_text . '</div>
		<div class="pg-unit-pictures">
			<div onmouseout="this.style.backgroundColor = \'\'" onmouseover="this.style.backgroundColor = \'#4B9AF6\'">
				<a href="' . htmlspecialchars( $pic_game_link->getFullURL( 'picGameAction=renderPermalink&id=' . $picturegame['id'] . '&voteID=' . $picturegame['id'] . '&key=' . $key ) ) . '">' . $imgOne . '</a>
			</div>
			<div onmouseout="this.style.backgroundColor = \'\'" onmouseover="this.style.backgroundColor = \'#FF0000\'">
				<a href="' . htmlspecialchars( $pic_game_link->getFullURL( 'picGameAction=renderPermalink&id=' . $picturegame['id'] . '&voteID=' . $picturegame['id'] . '&key=' . $key ) ) . '">' . $imgTwo . '</a>
			</div>
		</div>
		<div class="visualClear"></div>
	</div>';

		return $output;
	}

}
