<?php
/**
 * Mailer diagnostics.
 *
 * @package NanoPost_Email_Check
 */

defined( 'ABSPATH' ) || exit;

/**
 * Records how one wp_mail() call sent its message: the PHPMailer mailer and, for SMTP, the
 * host and port. Never reads the SMTP user name, password or any other setting.
 *
 * Use one object per wp_mail() call: start(), wp_mail(), stop(), then info().
 */
class NanoPost_Email_Check_Mailer {

	const TYPE_SMTP     = 'smtp';
	const TYPE_MAIL     = 'mail';
	const TYPE_SENDMAIL = 'sendmail';
	const TYPE_QMAIL    = 'qmail';
	const TYPE_OTHER    = 'other';
	const TYPE_PLUGIN   = 'plugin';

	/**
	 * How a plugin took over sending: a pre_wp_mail filter, or its own wp_mail() function.
	 */
	const VIA_PRE_WP_MAIL = 'pre_wp_mail';
	const VIA_WP_MAIL     = 'wp_mail';

	/**
	 * Stored text fields are cut at this many characters.
	 */
	const MAX_TEXT_LENGTH = 255;

	/**
	 * True when the pre_wp_mail filter ran (core wp_mail() was called).
	 *
	 * @var bool
	 */
	private $pre_fired = false;

	/**
	 * True when a pre_wp_mail filter returned a value, so wp_mail() did not send itself.
	 *
	 * @var bool
	 */
	private $short_circuited = false;

	/**
	 * True when phpmailer_init ran.
	 *
	 * @var bool
	 */
	private $init_fired = false;

	/**
	 * PHPMailer's Mailer, Host and Port as seen last in phpmailer_init.
	 *
	 * @var array
	 */
	private $seen = array();

	/**
	 * PHPMailer's Sendmail path. Used for the sendmail check only; not stored.
	 *
	 * @var string
	 */
	private $sendmail = '';

	/**
	 * Watch the next wp_mail() call. Runs last, after other plugins have set up PHPMailer.
	 */
	public function start() {
		add_filter( 'pre_wp_mail', array( $this, 'on_pre_wp_mail' ), PHP_INT_MAX );
		add_action( 'phpmailer_init', array( $this, 'on_phpmailer_init' ), PHP_INT_MAX );
	}

	/**
	 * Stop watching.
	 */
	public function stop() {
		remove_filter( 'pre_wp_mail', array( $this, 'on_pre_wp_mail' ), PHP_INT_MAX );
		remove_action( 'phpmailer_init', array( $this, 'on_phpmailer_init' ), PHP_INT_MAX );
	}

	/**
	 * Note whether another filter short-circuited wp_mail(). Returns the value unchanged.
	 *
	 * @param null|bool $value Short-circuit value. Null lets wp_mail() send.
	 * @return null|bool
	 */
	public function on_pre_wp_mail( $value ) {
		$this->pre_fired       = true;
		$this->short_circuited = null !== $value;
		return $value;
	}

	/**
	 * Read the final mailer from PHPMailer. Changes nothing.
	 *
	 * @param object $phpmailer PHPMailer instance.
	 */
	public function on_phpmailer_init( $phpmailer ) {
		$this->init_fired = true;
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property names.
		$this->seen     = array(
			'mailer' => isset( $phpmailer->Mailer ) ? (string) $phpmailer->Mailer : '',
			'host'   => isset( $phpmailer->Host ) ? (string) $phpmailer->Host : '',
			'port'   => isset( $phpmailer->Port ) ? (int) $phpmailer->Port : 0,
		);
		$this->sendmail = isset( $phpmailer->Sendmail ) ? (string) $phpmailer->Sendmail : '';
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * What the watched wp_mail() call used.
	 *
	 * @return array Normalized mailer info, see normalize(). Type '' when unknown.
	 */
	public function info() {
		if ( $this->short_circuited ) {
			return self::normalize(
				array(
					'type' => self::TYPE_PLUGIN,
					'via'  => self::VIA_PRE_WP_MAIL,
					'name' => self::pre_wp_mail_plugin( array( $this, 'on_pre_wp_mail' ) ),
				)
			);
		}

		if ( $this->init_fired ) {
			$mailer = strtolower( $this->seen['mailer'] );
			if ( self::TYPE_SMTP === $mailer ) {
				return self::normalize(
					array(
						'type' => self::TYPE_SMTP,
						'host' => $this->seen['host'],
						'port' => $this->seen['port'],
					)
				);
			}
			if ( in_array( $mailer, array( self::TYPE_MAIL, self::TYPE_SENDMAIL, self::TYPE_QMAIL ), true ) ) {
				return self::normalize( array( 'type' => $mailer ) );
			}
			if ( '' !== $mailer ) {
				return self::normalize(
					array(
						'type' => self::TYPE_OTHER,
						'name' => $this->seen['mailer'],
					)
				);
			}
		}

		if ( ! $this->pre_fired ) {
			// Core wp_mail() always runs pre_wp_mail first, so another wp_mail() ran.
			return self::normalize(
				array(
					'type' => self::TYPE_PLUGIN,
					'via'  => self::VIA_WP_MAIL,
					'name' => self::plugin_name( self::callback_file( 'wp_mail' ) ),
				)
			);
		}

		// wp_mail() stopped before phpmailer_init, for example on an invalid From address.
		return self::normalize( array() );
	}

	/**
	 * Cause of a failed send that the error text does not give: for mail(), sendmail and
	 * qmail, a sendmail program that is missing or not executable. The command is PHP's
	 * sendmail_path for mail(), or PHPMailer's Sendmail for sendmail and qmail.
	 *
	 * @param array $info Mailer info from info().
	 * @return string Empty when no cause is found.
	 */
	public function failure_cause( $info ) {
		if ( self::TYPE_MAIL === $info['type'] ) {
			$command = (string) ini_get( 'sendmail_path' );
			$label   = 'sendmail_path';
		} elseif ( in_array( $info['type'], array( self::TYPE_SENDMAIL, self::TYPE_QMAIL ), true ) ) {
			$command = $this->sendmail;
			$label   = 'Sendmail';
		} else {
			return '';
		}

		/**
		 * Filters the sendmail command that is checked after a failed send through mail(),
		 * sendmail or qmail.
		 *
		 * @param string $command Command, such as "/usr/sbin/sendmail -t -i".
		 * @param string $label   "sendmail_path" (PHP setting) or "Sendmail" (PHPMailer).
		 */
		$command = (string) apply_filters( 'nanopost_email_check_sendmail_command', $command, $label );

		return self::sendmail_cause( $command, $label );
	}

	/**
	 * Why a sendmail command cannot run: its program (the first word) is missing or not
	 * executable. Checks absolute paths only; empty when the program looks fine or
	 * cannot be checked.
	 *
	 * @param string $command Command, such as "/usr/sbin/sendmail -t -i".
	 * @param string $label   Name of the setting it came from.
	 * @return string Such as 'sendmail_path "/usr/sbin/sendmail -t -i": /usr/sbin/sendmail not found'.
	 */
	public static function sendmail_cause( $command, $label = 'sendmail_path' ) {
		$command = trim( (string) $command );
		$program = (string) strtok( $command, " \t" );

		// A relative program is found through PATH by the shell, and open_basedir can
		// hide a program that exists. Neither can be checked here without guessing.
		if ( '' === $program || '/' !== $program[0] || '' !== (string) ini_get( 'open_basedir' ) ) {
			return '';
		}

		// Fixed English, like the PHPMailer text it follows, so the error decoder can read it.
		if ( ! file_exists( $program ) ) {
			$problem = 'not found';
		} elseif ( is_dir( $program ) || ! is_executable( $program ) ) {
			$problem = 'not executable';
		} else {
			return '';
		}

		return sprintf( '%1$s "%2$s": %3$s %4$s', $label, $command, $program, $problem );
	}

	/**
	 * Clean stored mailer info. Unknown keys go; unknown types become ''.
	 *
	 * Keys: type ('smtp', 'mail', 'sendmail', 'qmail', 'other', 'plugin' or '' when not
	 * known), host and port (smtp), name (the Mailer value for 'other', the plugin name
	 * for 'plugin', or ''), via ('pre_wp_mail' or 'wp_mail', for 'plugin').
	 *
	 * @param mixed $data Mailer info.
	 * @return array
	 */
	public static function normalize( $data ) {
		$data  = is_array( $data ) ? $data : array();
		$types = array( self::TYPE_SMTP, self::TYPE_MAIL, self::TYPE_SENDMAIL, self::TYPE_QMAIL, self::TYPE_OTHER, self::TYPE_PLUGIN );
		$type  = isset( $data['type'] ) && is_string( $data['type'] ) && in_array( $data['type'], $types, true ) ? $data['type'] : '';
		$info  = array(
			'type' => $type,
			'host' => '',
			'port' => 0,
			'name' => '',
			'via'  => '',
		);

		if ( self::TYPE_SMTP === $type ) {
			$info['host'] = self::text( isset( $data['host'] ) ? $data['host'] : '' );
			$port         = isset( $data['port'] ) && is_numeric( $data['port'] ) ? (int) $data['port'] : 0;
			$info['port'] = $port > 0 && $port <= 65535 ? $port : 0;
		}
		if ( self::TYPE_OTHER === $type || self::TYPE_PLUGIN === $type ) {
			$info['name'] = self::text( isset( $data['name'] ) ? $data['name'] : '' );
		}
		if ( self::TYPE_PLUGIN === $type ) {
			$via         = isset( $data['via'] ) ? $data['via'] : '';
			$info['via'] = in_array( $via, array( self::VIA_PRE_WP_MAIL, self::VIA_WP_MAIL ), true ) ? $via : self::VIA_PRE_WP_MAIL;
		}

		return $info;
	}

	/**
	 * Sentence for the Status section, such as "Sends through SMTP smtp.example.com:587".
	 * Plain text: escape it on output.
	 *
	 * @param array $info Normalized mailer info.
	 * @return string Empty when the mailer is not known.
	 */
	public static function describe( $info ) {
		$info = self::normalize( $info );

		switch ( $info['type'] ) {
			case self::TYPE_SMTP:
				return '' === $info['host']
					? __( 'Sends through SMTP', 'nanopost-email-check' )
					/* translators: %s: SMTP server, such as "smtp.example.com:587". */
					: sprintf( __( 'Sends through SMTP %s', 'nanopost-email-check' ), self::server( $info ) );
			case self::TYPE_MAIL:
				return __( 'Sends through PHP mail()', 'nanopost-email-check' );
			case self::TYPE_SENDMAIL:
				return __( 'Sends through sendmail', 'nanopost-email-check' );
			case self::TYPE_QMAIL:
				return __( 'Sends through qmail', 'nanopost-email-check' );
			case self::TYPE_OTHER:
				/* translators: %s: PHPMailer mailer name set by another plugin, such as "sendgrid". */
				return sprintf( __( 'Sends through the "%s" mailer', 'nanopost-email-check' ), $info['name'] );
			case self::TYPE_PLUGIN:
				if ( self::VIA_WP_MAIL === $info['via'] ) {
					return '' === $info['name']
						? __( 'Sending is handled by another plugin, which replaces wp_mail()', 'nanopost-email-check' )
						/* translators: %s: plugin name. */
						: sprintf( __( 'Sending is handled by %s, which replaces wp_mail()', 'nanopost-email-check' ), $info['name'] );
				}
				return '' === $info['name']
					? __( 'Sending is handled by another plugin, through the pre_wp_mail filter', 'nanopost-email-check' )
					/* translators: %s: plugin name. */
					: sprintf( __( 'Sending is handled by %s, through the pre_wp_mail filter', 'nanopost-email-check' ), $info['name'] );
		}

		return '';
	}

	/**
	 * Short form for the log, such as "SMTP smtp.example.com:587" or "PHP mail()". Plain
	 * text: escape it on output.
	 *
	 * @param array $info Normalized mailer info.
	 * @return string Empty when the mailer is not known.
	 */
	public static function short_label( $info ) {
		$info = self::normalize( $info );

		switch ( $info['type'] ) {
			case self::TYPE_SMTP:
				return trim( 'SMTP ' . self::server( $info ) );
			case self::TYPE_MAIL:
				return 'PHP mail()';
			case self::TYPE_SENDMAIL:
			case self::TYPE_QMAIL:
				return $info['type'];
			case self::TYPE_OTHER:
				return $info['name'];
			case self::TYPE_PLUGIN:
				return '' === $info['name']
					? __( 'Another plugin', 'nanopost-email-check' )
					/* translators: %s: plugin name. */
					: sprintf( __( 'Plugin: %s', 'nanopost-email-check' ), $info['name'] );
		}

		return '';
	}

	/**
	 * SMTP host and port, as "host:port". The port is left out when the host names one.
	 *
	 * @param array $info Normalized mailer info.
	 * @return string
	 */
	private static function server( $info ) {
		if ( '' === $info['host'] || 0 === $info['port'] || preg_match( '/:\d+$/', $info['host'] ) ) {
			return $info['host'];
		}
		return $info['host'] . ':' . $info['port'];
	}

	/**
	 * Stored text: a string with no tags or line breaks, cut to MAX_TEXT_LENGTH.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function text( $value ) {
		$value = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, self::MAX_TEXT_LENGTH ) : substr( $value, 0, self::MAX_TEXT_LENGTH );
	}

	/**
	 * Name of the plugin behind the pre_wp_mail filter, when exactly one other callback
	 * is hooked (only that one can have returned a value). Empty otherwise.
	 *
	 * @param callable $own Own callback, left out.
	 * @return string
	 */
	private static function pre_wp_mail_plugin( $own ) {
		global $wp_filter;
		if ( ! isset( $wp_filter['pre_wp_mail'] ) || ! is_object( $wp_filter['pre_wp_mail'] ) ) {
			return '';
		}

		$files = array();
		foreach ( (array) $wp_filter['pre_wp_mail']->callbacks as $callbacks ) {
			foreach ( (array) $callbacks as $callback ) {
				if ( isset( $callback['function'] ) && $own !== $callback['function'] ) {
					$files[] = self::callback_file( $callback['function'] );
				}
			}
		}

		return 1 === count( $files ) ? self::plugin_name( $files[0] ) : '';
	}

	/**
	 * File that defines a callback, or '' when it cannot be found.
	 *
	 * @param mixed $callback Callback.
	 * @return string
	 */
	private static function callback_file( $callback ) {
		try {
			if ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
				$reflection = new ReflectionMethod( $callback );
			} elseif ( is_array( $callback ) && 2 === count( $callback ) ) {
				$reflection = new ReflectionMethod( reset( $callback ), end( $callback ) );
			} elseif ( is_string( $callback ) || $callback instanceof Closure ) {
				$reflection = new ReflectionFunction( $callback );
			} elseif ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
				$reflection = new ReflectionMethod( $callback, '__invoke' );
			} else {
				return '';
			}
		} catch ( ReflectionException $e ) {
			return '';
		}

		$file = $reflection->getFileName();
		return is_string( $file ) ? wp_normalize_path( $file ) : '';
	}

	/**
	 * Name of the plugin a file belongs to, from the plugin header. Empty for files of
	 * WordPress, the theme, or anything not clearly in one plugin.
	 *
	 * @param string $file File path.
	 * @return string
	 */
	private static function plugin_name( $file ) {
		if ( '' === $file ) {
			return '';
		}
		$plugins_dir = trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );
		$mu_dir      = trailingslashit( wp_normalize_path( WPMU_PLUGIN_DIR ) );

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( 0 === strpos( $file, $plugins_dir ) ) {
			$relative = substr( $file, strlen( $plugins_dir ) );
			if ( false === strpos( $relative, '/' ) ) {
				$data = get_plugin_data( $file, false, false );
				return self::text( $data['Name'] );
			}
			$plugins = get_plugins( '/' . strtok( $relative, '/' ) );
			$first   = reset( $plugins );
			return 1 === count( $plugins ) && ! empty( $first['Name'] ) ? self::text( $first['Name'] ) : '';
		}

		if ( 0 === strpos( $file, $mu_dir ) && false === strpos( substr( $file, strlen( $mu_dir ) ), '/' ) ) {
			$data = get_plugin_data( $file, false, false );
			return self::text( $data['Name'] );
		}

		return '';
	}
}
