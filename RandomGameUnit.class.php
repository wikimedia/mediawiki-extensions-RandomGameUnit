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

class RandomGameUnit {
	/**
	 * Set up the <randomgameunit> parser hook
	 *
	 * @param Parser $parser Instance of Parser
	 * @return bool
	 */
	public static function registerTag( &$parser ) {
		$parser->setHook( 'randomgameunit', array( __CLASS__, 'getRandomGameUnit' ) );
		return true;
	}

	public static function getRandomGameUnit( $input = '', $argsv = array() ) {
		global $wgRandomGameDisplay, $wgMemc;

		$random_games = array();
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

		if ( !Hooks::run( 'RandomGameUnit', array( &$random_games, &$custom_fallback ) ) ) {
			wfDebug( __METHOD__ . ": RandomGameUnit hook messed up the page!\n" );
		}

		if ( count( $random_games ) == 0 ) {
			return '';
		}

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
				$quiz = array();
				// Try cache
				$key = wfMemcKey( 'quiz', 'order', 'q_id', 'count', $count );
				$data = $wgMemc->get( $key );
				if ( $data ) {
					wfDebugLog( 'RandomGameUnit', "Got quiz list ($count) from cache" );
					$quiz = $data;
				} else {
					wfDebugLog( 'RandomGameUnit', "Got quiz list ($count) ordered by q_id from DB" );
					$dbr = wfGetDB( DB_SLAVE );
					$params['LIMIT'] = $count;
					$params['ORDER BY'] = 'q_id DESC';
					$res = $dbr->select(
						'quizgame_questions',
						array(
							'q_id', 'q_text', 'q_picture',
							'UNIX_TIMESTAMP(q_date) AS quiz_date'
						),
						/* WHERE */array(),
						__METHOD__,
						$params
					);
					foreach ( $res as $row ) {
						$quiz[] = array(
							'id' => $row->q_id,
							'text' => $row->q_text,
							'image' => $row->q_picture,
							'timestamp' => $row->quiz_date
						);
					}
					$wgMemc->set( $key, $quiz, 60 * 10 );
				}
				$random_quiz = $quiz[array_rand( $quiz )];
				if ( $random_quiz ) {
					return self::displayQuiz( $random_quiz );
				}
				break;
			case 'picgame':
				// Try cache
				$pics = array();
				$key = wfMemcKey( 'picgame', 'order', 'q_id' , 'count', $count );
				$data = $wgMemc->get( $key );
				if ( $data ) {
					wfDebugLog( 'RandomGameUnit', "Got picture game list ($count) ordered by id from cache" );
					$pics = $data;
				} else {
					wfDebugLog( 'RandomGameUnit', "Got picture game list ($count) ordered by id from DB" );
					$dbr = wfGetDB( DB_SLAVE );
					$params['LIMIT'] = $count;
					$params['ORDER BY'] = 'id DESC';
					$res = $dbr->select(
						'picturegame_images',
						array( 'id', 'title', 'img1', 'img2', 'UNIX_TIMESTAMP(pg_date) AS pic_game_date' ),
						/* WHERE */array( 'flag <> 1' /* 1 = PICTUREGAME_FLAG_FLAGGED */ ),
						__METHOD__,
						$params
					);
					foreach ( $res as $row ) {
						$pics[] = array(
							'id' => $row->id,
							'title' => $row->title,
							'img1' => $row->img1,
							'img2' => $row->img2,
							'timestamp' => $row->pic_game_date
						);
					}
					$wgMemc->set( $key, $pics, 60 * 10 );
				}
				$random_picgame = $pics[array_rand( $pics )];
				if ( $random_picgame ) {
					return self::displayPictureGame( $random_picgame );
				}

				break;
			case 'custom':
				if ( $custom_fallback ) {
					return call_user_func( $custom_fallback, $count );
				}
				break;
		}
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
			$poll_image = wfFindFile( $poll['image'] );
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

	public static function wfDisplayQuiz( $quiz ) {
		global $wgRandomImageSize;

		$quiz_title = SpecialPage::getTitleFor( 'QuizGameHome' );
		$output = '<div class="game-unit-container">
			<h2>' . wfMessage( 'game-unit-quiz-title' )->plain() . '</h2>
			<div class="quiz-unit-title"><a href="' . htmlspecialchars( $quiz_title->getFullURL( "questionGameAction=renderPermalink&permalinkID={$quiz['id']}" ) ) . '" rel="nofollow">' . $quiz['text'] . '</a></div>';

		if ( $quiz['image'] ) {
			$quiz_image_width = $wgRandomImageSize;
			$quiz_image = wfFindFile( $quiz['image'] );
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

		$img_one = wfFindFile( $picturegame['img1'] );
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

		$img_two = wfFindFile( $picturegame['img2'] );
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
		#global $wgUser;
		#$key = md5( $picturegame['id'] . md5( $wgUser->getName() ) ); // the 2nd param should be PictureGameHome::$SALT but that is a private member variable

		// @todo FIXME/CHECKME: voteImage=1 seems to be just cruft in the URL
		$output = '<div class="game-unit-container">
		<h2>' . wfMessage( 'game-unit-picturegame-title' )->plain() . '</h2>
		<div class="pg-unit-title">' . $title_text . '</div>
		<div class="pg-unit-pictures">
			<div onmouseout="this.style.backgroundColor = \'\'" onmouseover="this.style.backgroundColor = \'#4B9AF6\'">
				<a href="' . htmlspecialchars( $pic_game_link->getFullURL( 'picGameAction=renderPermalink&id=' . $picturegame['id'] . '&voteID=' . $picturegame['id'] . '&voteImage=1&key=' . $key ) ) . '">' . $imgOne . '</a>
			</div>
			<div onmouseout="this.style.backgroundColor = \'\'" onmouseover="this.style.backgroundColor = \'#FF0000\'">
				<a href="' . htmlspecialchars( $pic_game_link->getFullURL( 'picGameAction=renderPermalink&id=' . $picturegame['id'] . '&voteID=' . $picturegame['id'] . '&voteImage=1&key=' . $key ) ) . '">' . $imgTwo . '</a>
			</div>
		</div>
		<div class="visualClear"></div>
	</div>';

		return $output;
	}

}