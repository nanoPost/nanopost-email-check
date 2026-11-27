<?php
/**
 * The state of the canary, as Email Canary (the nanoPost app) sees it.
 *
 * @package NanoPost_Email_Check
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the last "check" block the app sent, and the arrival of each test in the log.
 * The app sends the block in its answers to the announce call, the test result call and
 * "GET /check". So wp-admin shows the same state as the alert emails, also for scheduled
 * tests: one WP-Cron event per announced test asks for its arrival after the deadline.
 *
 * No call runs in a page load. The notices and Site Health read the stored block only.
 */
class NanoPost_Email_Check_Canary {

	/**
	 * The last block, with the time the plugin got it. Autoloaded: the notices on every
	 * wp-admin page read it. It holds no secret.
	 */
	const OPTION = 'nanopost_email_check_canary';

	/**
	 * Single WP-Cron event that asks for the arrival of one test. Args: test ID, try (1 or 2).
	 */
	const FETCH_HOOK = 'nanopost_email_check_fetch_test';

	/**
	 * The first fetch runs this long after the deadline (expect_by), the second this long
	 * after the first.
	 */
	const FETCH_AFTER = 120;
	const FETCH_RETRY = 600;

	/**
	 * Delay of the first fetch when the answer gives no usable deadline: the app's 15
	 * minutes plus FETCH_AFTER.
	 */
	const FETCH_DEFAULT = 1020;

	/**
	 * "GET /check" from the plugin screen runs at most once in this many seconds.
	 */
	const SCREEN_THROTTLE = 60;
	const SCREEN_LOCK     = 'nanopost_email_check_canary_lock';

	/**
	 * SPF, DKIM and DMARC results that count as a problem. "none", "neutral" and
	 * "softfail" show as a warning only.
	 */
	const AUTH_PROBLEMS = array( 'fail', 'permerror', 'temperror' );

	/**
	 * Values of "arrival" the plugin knows. Others show as "Open nanoPost to see".
	 */
	const ARRIVALS = array( 'waiting', 'arrived', 'missing', 'unknown', 'send_failed' );

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( self::FETCH_HOOK, array( __CLASS__, 'fetch_test' ), 10, 2 );
		add_filter( 'site_status_tests', array( __CLASS__, 'site_health_tests' ) );
	}

	// -------------------------------------------------------------------------------
	// The block.

	/**
	 * The stored block, normalized, or null when the plugin has none.
	 *
	 * @return array|null
	 */
	public static function block() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) || ! isset( $stored['block'] ) || ! is_array( $stored['block'] ) ) {
			return null;
		}
		return self::normalize( $stored['block'] );
	}

	/**
	 * Unix time the plugin got the stored block, or 0.
	 *
	 * @return int
	 */
	public static function got_at() {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) && isset( $stored['got_at'] ) ? (int) $stored['got_at'] : 0;
	}

	/**
	 * Take the block from an answer of the app, when it has one. An older block (by
	 * observed_at) does not replace a newer one, so answers in the wrong order do no harm.
	 * The last_test of the block also fills its row in the log.
	 *
	 * @param array $body Decoded answer.
	 * @return bool True when the answer had a block.
	 */
	public static function take( $body ) {
		if ( ! is_array( $body ) || ! isset( $body['check'] ) || ! is_array( $body['check'] ) ) {
			return false;
		}
		$block   = self::normalize( $body['check'] );
		$current = self::block();
		if ( null === $current || $block['observed_at'] >= $current['observed_at'] ) {
			update_option(
				self::OPTION,
				array(
					'block'  => $block,
					'got_at' => time(),
				)
			);
		}
		if ( null !== $block['last_test'] ) {
			self::record_test( $block['last_test'] );
		}
		return true;
	}

	/**
	 * Forget the block and every pending fetch. Used when the connection ends.
	 */
	public static function forget() {
		delete_option( self::OPTION );
		delete_transient( self::SCREEN_LOCK );
		wp_unschedule_hook( self::FETCH_HOOK );
	}

	/**
	 * A block with every key present and every value checked. Times become Unix times
	 * (0 for none). Strings that are not a known value stay as they came (sanitized), so
	 * the screen can show "Open nanoPost to see" for them.
	 *
	 * @param array $raw Block from the app.
	 * @return array
	 */
	public static function normalize( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();

		$alerts = array();
		if ( isset( $raw['alerts_to'] ) && is_array( $raw['alerts_to'] ) ) {
			foreach ( $raw['alerts_to'] as $entry ) {
				$email = is_array( $entry ) && isset( $entry['email'] ) && is_string( $entry['email'] ) ? sanitize_email( $entry['email'] ) : '';
				if ( '' === $email ) {
					continue;
				}
				$alerts[] = array(
					'email' => $email,
					'state' => self::word( $entry, 'state' ),
				);
			}
		}

		$mail = null;
		if ( isset( $raw['confirm_mail'] ) && is_array( $raw['confirm_mail'] ) ) {
			$mail = array(
				'state'           => self::word( $raw['confirm_mail'], 'state' ),
				'next_allowed_at' => self::time( $raw['confirm_mail'], 'next_allowed_at' ),
			);
		}

		return array(
			'observed_at'      => self::time( $raw, 'observed_at' ),
			'watching'         => self::word( $raw, 'watching' ),
			'confirm_by'       => self::time( $raw, 'confirm_by' ),
			'stopped_reason'   => self::word( $raw, 'stopped_reason' ),
			'confirm_mail'     => $mail,
			'arrival'          => self::word( $raw, 'arrival' ),
			'next_expected_by' => self::time( $raw, 'next_expected_by' ),
			'sending'          => self::word( $raw, 'sending' ),
			'receive_path'     => self::word( $raw, 'receive_path' ),
			'last_arrival'     => isset( $raw['last_arrival'] ) && is_array( $raw['last_arrival'] ) ? self::normalize_arrival( $raw['last_arrival'] ) : null,
			'last_test'        => isset( $raw['last_test'] ) && is_array( $raw['last_test'] ) ? self::normalize_test( $raw['last_test'] ) : null,
			'alerts_to'        => $alerts,
			'check_url'        => self::url( $raw, 'check_url' ),
		);
	}

	/**
	 * The last_arrival object.
	 *
	 * @param array $raw Object.
	 * @return array
	 */
	private static function normalize_arrival( $raw ) {
		return array(
			'received_at' => self::time( $raw, 'received_at' ),
			'test_id'     => self::test_id( $raw ),
			'spf'         => self::word( $raw, 'spf' ),
			'dkim'        => self::word( $raw, 'dkim' ),
			'dmarc'       => self::word( $raw, 'dmarc' ),
		);
	}

	/**
	 * A test: last_test of the block, or the answer of "GET /tests/<id>" (which names the
	 * arrival "status").
	 *
	 * @param array $raw Object.
	 * @return array
	 */
	public static function normalize_test( $raw ) {
		$arrival = self::word( $raw, 'arrival' );
		if ( '' === $arrival ) {
			$arrival = self::word( $raw, 'status' );
		}
		return array(
			'test_id'    => self::test_id( $raw ),
			'sent_at'    => self::time( $raw, 'sent_at' ),
			'arrival'    => $arrival,
			'arrived_at' => self::time( $raw, 'arrived_at' ),
			'spf'        => self::word( $raw, 'spf' ),
			'dkim'       => self::word( $raw, 'dkim' ),
			'dmarc'      => self::word( $raw, 'dmarc' ),
			'reason'     => self::word( $raw, 'reason' ),
			'help_url'   => self::help_url( $raw ),
		);
	}

	/**
	 * A short code: lower case a-z, 0-9 and "_", or ''.
	 *
	 * @param array  $raw Object.
	 * @param string $key Key.
	 * @return string
	 */
	private static function word( $raw, $key ) {
		return isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) ? substr( sanitize_key( $raw[ $key ] ), 0, 40 ) : '';
	}

	/**
	 * An ISO 8601 time as a Unix time, or 0. A Unix time (a block that was already
	 * normalized) stays as it is.
	 *
	 * @param array  $raw Object.
	 * @param string $key Key.
	 * @return int
	 */
	private static function time( $raw, $key ) {
		if ( isset( $raw[ $key ] ) && is_int( $raw[ $key ] ) ) {
			return max( 0, $raw[ $key ] );
		}
		if ( ! isset( $raw[ $key ] ) || ! is_string( $raw[ $key ] ) || '' === $raw[ $key ] ) {
			return 0;
		}
		$time = strtotime( $raw[ $key ] );
		return false === $time ? 0 : (int) $time;
	}

	/**
	 * A test ID (12 characters, a-z and 0-9), or ''.
	 *
	 * @param array $raw Object.
	 * @return string
	 */
	private static function test_id( $raw ) {
		return isset( $raw['test_id'] ) && is_string( $raw['test_id'] ) && preg_match( '/^[a-z0-9]{12}$/', $raw['test_id'] ) ? $raw['test_id'] : '';
	}

	/**
	 * An http(s) URL, or ''.
	 *
	 * @param array  $raw Object.
	 * @param string $key Key.
	 * @return string
	 */
	private static function url( $raw, $key ) {
		return isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) ? esc_url_raw( $raw[ $key ], array( 'https', 'http' ) ) : '';
	}

	/**
	 * The help link of a reason: only a page on nanopo.st or app.nanopo.st.
	 *
	 * @param array $raw Object.
	 * @return string
	 */
	private static function help_url( $raw ) {
		$url  = self::url( $raw, 'help_url' );
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return in_array( $host, array( 'nanopo.st', 'app.nanopo.st' ), true ) && 0 === strpos( $url, 'https://' ) ? $url : '';
	}

	// -------------------------------------------------------------------------------
	// Fetches.

	/**
	 * Book the fetch of one announced test, after its deadline. The delay is the deadline
	 * minus the time of the answer, both from the app's clock (the Date header), so a wrong
	 * site clock has no effect.
	 *
	 * @param string $test_id Test ID.
	 * @param array  $call    Result of the announce call (see NanoPost_Email_Check_App::request()).
	 */
	public static function schedule_fetch( $test_id, $call ) {
		$delay     = self::FETCH_DEFAULT;
		$expect_by = isset( $call['body']['expect_by'] ) && is_string( $call['body']['expect_by'] ) ? strtotime( $call['body']['expect_by'] ) : false;
		$now       = isset( $call['date'] ) && '' !== $call['date'] ? strtotime( $call['date'] ) : false;
		if ( false !== $expect_by && false !== $now && $expect_by >= $now ) {
			$delay = min( DAY_IN_SECONDS, $expect_by - $now + self::FETCH_AFTER );
		}
		wp_schedule_single_event( time() + $delay, self::FETCH_HOOK, array( (string) $test_id, 1 ) );
	}

	/**
	 * WP-Cron: ask for the arrival of one test. One more try later when it has no final
	 * answer yet, or did not arrive and has no reason yet. At most two tries.
	 *
	 * @param string $test_id Test ID.
	 * @param int    $attempt 1 or 2.
	 */
	public static function fetch_test( $test_id, $attempt = 1 ) {
		if ( ! preg_match( '/^[a-z0-9]{12}$/', (string) $test_id ) ) {
			return;
		}
		$call = NanoPost_Email_Check_App::test_result( $test_id );
		if ( ! $call['ok'] ) {
			return;
		}
		$test = self::take_test_answer( $call['body'] );
		$open = 'waiting' === $test['arrival'] || ( 'missing' === $test['arrival'] && '' === $test['reason'] );
		if ( $open && (int) $attempt < 2 ) {
			wp_schedule_single_event( time() + self::FETCH_RETRY, self::FETCH_HOOK, array( (string) $test_id, 2 ) );
		}
	}

	/**
	 * Take the answer of "GET /tests/<id>": the test into its log row, and the block.
	 *
	 * @param array $body Decoded answer.
	 * @return array The test (normalize_test()).
	 */
	public static function take_test_answer( $body ) {
		$test = self::normalize_test( is_array( $body ) ? $body : array() );
		self::record_test( $test );
		self::take( $body );
		return $test;
	}

	/**
	 * Store the arrival of one test in its log row.
	 *
	 * @param array $test Test (normalize_test()).
	 */
	private static function record_test( $test ) {
		if ( '' === $test['test_id'] ) {
			return;
		}
		$fields = $test;
		unset( $fields['test_id'], $fields['sent_at'] );
		$fields['fetched_at'] = time();
		NanoPost_Email_Check_Log::update_test( $test['test_id'], $fields );
	}

	/**
	 * "GET /check" for the plugin screen, at most once a minute. Returns true when a new
	 * block came.
	 *
	 * @return bool
	 */
	public static function refresh_for_screen() {
		if ( false !== get_transient( self::SCREEN_LOCK ) ) {
			return false;
		}
		set_transient( self::SCREEN_LOCK, 1, self::SCREEN_THROTTLE );
		$before = self::block();
		$call   = NanoPost_Email_Check_App::check_state();
		if ( ! $call['ok'] || ! self::take( $call['body'] ) ) {
			return false;
		}
		return self::block() !== $before;
	}

	// -------------------------------------------------------------------------------
	// What the block means.

	/**
	 * True when the stored block is old: its next deadline has passed with no newer block,
	 * or, with no deadline, it is older than twice the interval.
	 *
	 * @param array $block Block.
	 * @return bool
	 */
	public static function is_old( $block ) {
		if ( $block['next_expected_by'] > 0 ) {
			return time() > $block['next_expected_by'];
		}
		$from = $block['observed_at'] > 0 ? $block['observed_at'] : self::got_at();
		return time() > $from + 2 * NanoPost_Email_Check_Scheduler::current_interval_seconds();
	}

	/**
	 * True when Email Canary cannot tell the state now (its own outage).
	 *
	 * @param array $block Block.
	 * @return bool
	 */
	public static function cannot_tell( $block ) {
		return 'unknown' === $block['receive_path'] || 'unknown' === $block['arrival'];
	}

	/**
	 * The open problems that Email Canary also emails about, for the notices and Site
	 * Health. None while it cannot tell, while the block is old, or while it does not
	 * watch.
	 *
	 * @param array|null $block Block, or null.
	 * @return array[] Each: type ("missing" or "auth"), key (changes with each new problem),
	 *                 and the facts the text needs.
	 */
	public static function problems( $block ) {
		if ( null === $block || 'on' !== $block['watching'] || self::cannot_tell( $block ) || self::is_old( $block ) ) {
			return array();
		}

		$problems = array();
		$test     = $block['last_test'];

		if ( 'missing' === $block['arrival'] ) {
			$problems[] = array(
				'type'     => 'missing',
				'key'      => 'canary-missing:' . ( null === $test ? $block['observed_at'] : $test['test_id'] . $test['sent_at'] ),
				'sent_at'  => null === $test ? 0 : $test['sent_at'],
				'reason'   => null === $test ? '' : $test['reason'],
				'help_url' => null === $test ? '' : $test['help_url'],
			);
		}

		$failing = null === $block['last_arrival'] ? array() : self::failing( $block['last_arrival'] );
		if ( array() !== $failing ) {
			$problems[] = array(
				'type'     => 'auth',
				'key'      => 'canary-auth:' . $block['last_arrival']['received_at'] . ':' . implode( ',', $failing ),
				'failing'  => $failing,
				'help_url' => null !== $test && 'auth_failed' === $test['reason'] ? $test['help_url'] : '',
			);
		}

		return $problems;
	}

	/**
	 * Names of the methods (SPF, DKIM, DMARC) whose result is a problem.
	 *
	 * @param array $results Array with spf, dkim and dmarc.
	 * @return string[]
	 */
	public static function failing( $results ) {
		$failing = array();
		foreach ( array(
			'spf'   => 'SPF',
			'dkim'  => 'DKIM',
			'dmarc' => 'DMARC',
		) as $key => $name ) {
			if ( in_array( $results[ $key ], self::AUTH_PROBLEMS, true ) ) {
				$failing[] = $name;
			}
		}
		return $failing;
	}

	/**
	 * The plugin's text for a reason code. Empty for no reason; "Open nanoPost to see"
	 * for a code it does not know.
	 *
	 * @param string $reason Code.
	 * @return string
	 */
	public static function reason_text( $reason ) {
		$texts = array(
			'not_sent'            => __( 'WordPress reported that it could not send the email.', 'nanopost-email-check' ),
			'refused_dmarc'       => __( 'Our mail server refused it because of the domain\'s DMARC policy.', 'nanopost-email-check' ),
			'refused_spf'         => __( 'Our mail server refused it because of the domain\'s SPF record.', 'nanopost-email-check' ),
			'refused_blocklist'   => __( 'Our mail server refused it: the IP address of the sending server is on a blocklist.', 'nanopost-email-check' ),
			'refused_reverse_dns' => __( 'Our mail server refused it: the sending server has no reverse DNS name.', 'nanopost-email-check' ),
			'refused_size'        => __( 'Our mail server refused it: the email was too large.', 'nanopost-email-check' ),
			'refused_spam'        => __( 'Our mail server refused it as spam.', 'nanopost-email-check' ),
			'refused'             => __( 'Our mail server refused it.', 'nanopost-email-check' ),
			'dns_changed'         => __( 'The domain\'s SPF or DMARC record changed since the last good email.', 'nanopost-email-check' ),
			'auth_failed'         => __( 'It did not pass SPF, DKIM or DMARC, which passed before.', 'nanopost-email-check' ),
		);
		if ( '' === $reason ) {
			return '';
		}
		return isset( $texts[ $reason ] ) ? $texts[ $reason ] : self::open_text();
	}

	/**
	 * Text for a value the plugin does not know.
	 *
	 * @return string
	 */
	public static function open_text() {
		return __( 'Open nanoPost to see.', 'nanopost-email-check' );
	}

	/**
	 * The words for one result of SPF, DKIM or DMARC. A pass is the name alone.
	 *
	 * @param string $method spf, dkim or dmarc.
	 * @param string $value  Result.
	 * @return string
	 */
	public static function auth_words( $method, $value ) {
		$name = strtoupper( $method );
		switch ( $value ) {
			case 'pass':
				return $name;
			case 'none':
				/* translators: %s: SPF or DMARC. */
				return 'dkim' === $method ? __( 'DKIM not signed', 'nanopost-email-check' ) : sprintf( __( '%s: no record', 'nanopost-email-check' ), $name );
			case 'fail':
				/* translators: %s: SPF, DKIM or DMARC. */
				return sprintf( __( '%s did not pass', 'nanopost-email-check' ), $name );
			case 'softfail':
				/* translators: %s: SPF. */
				return sprintf( __( '%s soft fail', 'nanopost-email-check' ), $name );
			case 'neutral':
				/* translators: %s: SPF, DKIM or DMARC. */
				return sprintf( __( '%s neutral', 'nanopost-email-check' ), $name );
			case 'temperror':
			case 'permerror':
				/* translators: %s: SPF, DKIM or DMARC. */
				return sprintf( __( '%s error', 'nanopost-email-check' ), $name );
			case '':
				/* translators: %s: SPF, DKIM or DMARC. */
				return sprintf( __( '%s: no result', 'nanopost-email-check' ), $name );
			default:
				/* translators: %s: SPF, DKIM or DMARC. */
				return sprintf( __( '%s: open nanoPost to see', 'nanopost-email-check' ), $name );
		}
	}

	/**
	 * Three labels for SPF, DKIM and DMARC. Each has a mark as well as a colour, because
	 * colour alone cannot be read by everyone.
	 *
	 * @param array $results Array with spf, dkim and dmarc.
	 * @return string HTML.
	 */
	public static function auth_labels( $results ) {
		$html = '';
		foreach ( array( 'spf', 'dkim', 'dmarc' ) as $method ) {
			$value = $results[ $method ];
			if ( 'pass' === $value ) {
				$kind = 'pass';
				$mark = '&#10003;';
			} elseif ( in_array( $value, self::AUTH_PROBLEMS, true ) ) {
				$kind = 'fail';
				$mark = '&#10005;';
			} else {
				$kind = 'warn';
				$mark = '!';
			}
			$html .= sprintf(
				'<span class="nanopost-email-check-auth nanopost-email-check-auth-%1$s"><span aria-hidden="true">%2$s</span> %3$s</span> ',
				esc_attr( $kind ),
				$mark,
				esc_html( self::auth_words( $method, $value ) )
			);
		}
		return trim( $html );
	}

	/**
	 * Inline styles for the labels. Printed once on the screens that show them.
	 *
	 * @return string CSS.
	 */
	public static function label_css() {
		return '.nanopost-email-check-auth{display:inline-block;padding:0 6px;margin:2px 2px 2px 0;border-radius:3px;border:1px solid;font-size:12px;line-height:1.8;white-space:nowrap}'
			. '.nanopost-email-check-auth-pass{color:#00450c;background:#edfaef;border-color:#00a32a}'
			. '.nanopost-email-check-auth-warn{color:#4d3300;background:#fcf9e8;border-color:#dba617}'
			. '.nanopost-email-check-auth-fail{color:#8a1f1f;background:#fcf0f1;border-color:#d63638}';
	}

	// -------------------------------------------------------------------------------
	// Site Health.

	/**
	 * Add the Site Health test while the site is connected.
	 *
	 * @param array $tests Tests.
	 * @return array
	 */
	public static function site_health_tests( $tests ) {
		if ( NanoPost_Email_Check_Connection::is_connected() ) {
			$tests['direct']['nanopost_email_check_canary'] = array(
				'label' => __( 'Email Canary', 'nanopost-email-check' ),
				'test'  => array( __CLASS__, 'site_health' ),
			);
		}
		return $tests;
	}

	/**
	 * The Site Health result, from the stored block. No call.
	 *
	 * @return array
	 */
	public static function site_health() {
		$block  = self::block();
		$result = array(
			'label'       => '',
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Email', 'nanopost-email-check' ),
				'color' => 'blue',
			),
			'description' => '',
			'actions'     => sprintf( '<p><a href="%1$s">%2$s</a></p>', esc_url( NanoPost_Email_Check_Admin::page_url() ), esc_html__( 'View results', 'nanopost-email-check' ) ),
			'test'        => 'nanopost_email_check_canary',
		);

		$problems = self::problems( $block );
		if ( array() !== $problems ) {
			$result['status']         = 'recommended';
			$result['badge']['color'] = 'red';
			$result['label']          = self::problem_text( $problems[0] );
			$result['description']    = '<p>' . esc_html__( 'Email Canary also emailed you about it.', 'nanopost-email-check' ) . '</p>';
			return $result;
		}

		if ( null === $block || self::cannot_tell( $block ) || self::is_old( $block ) || null === $block['last_arrival'] || 0 === $block['last_arrival']['received_at'] ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'Email Canary cannot tell now whether test emails arrive', 'nanopost-email-check' );
			$result['description'] = '<p>' . esc_html__( 'Open the plugin screen to see the last result.', 'nanopost-email-check' ) . '</p>';
			return $result;
		}

		$result['label'] = sprintf(
			/* translators: %s: time span, such as "3 hours". */
			__( 'Test email arrived at nanoPost %s ago', 'nanopost-email-check' ),
			human_time_diff( $block['last_arrival']['received_at'], time() )
		);
		$result['description'] = '<p>' . esc_html__( 'Email Canary watches the test emails of this site and emails you when one does not arrive.', 'nanopost-email-check' ) . '</p>';
		return $result;
	}

	/**
	 * The core sentence of a problem, the same as in the notice.
	 *
	 * @param array $problem Problem (problems()).
	 * @return string
	 */
	public static function problem_text( $problem ) {
		if ( 'missing' === $problem['type'] ) {
			if ( $problem['sent_at'] > 0 ) {
				/* translators: %s: date and time the test email was sent. */
				return sprintf( __( 'Email Canary: the test email of %s did not arrive.', 'nanopost-email-check' ), NanoPost_Email_Check_Admin::format_date( $problem['sent_at'] ) );
			}
			return __( 'Email Canary: the test email did not arrive.', 'nanopost-email-check' );
		}
		/* translators: %s: names such as "DMARC" or "SPF, DMARC". */
		return sprintf( __( 'Email Canary: the test email arrived, but did not pass %s.', 'nanopost-email-check' ), implode( ', ', $problem['failing'] ) );
	}
}
