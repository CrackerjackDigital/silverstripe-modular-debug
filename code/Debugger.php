<?php

namespace Modular;

require_once( __DIR__ . '/traits/debugging.php' );
require_once( __DIR__ . '/traits/logging_email.php' );
require_once( __DIR__ . '/traits/logging_file.php' );
require_once( __DIR__ . '/traits/logging_screen.php' );

use Cookie;
use Director;
use Modular\Interfaces\Debugger as DebuggerInterface;
use Modular\Interfaces\Logger as LoggerInterface;
use Modular\Traits\bitfield;
use Modular\Traits\enabler;
use Modular\Traits\logging_email;
use Modular\Traits\logging_file;
use Modular\Traits\logging_screen;
use Modular\Traits\safe_paths;

class Debugger extends Object implements LoggerInterface, DebuggerInterface {
	use bitfield;
	use enabler;
	use logging_file;
	use logging_screen;
	use logging_email;
	use safe_paths;

	const DefaultSendEmailsFrom = 'servers@moveforward.co.nz';
	const DefaultSendEmailsTo   = 'servers@moveforward.co.nz';

	const CurrentEnvironment = SS_ENVIRONMENT_TYPE;

	private static $environment_levels = [
		'dev'  => self::DebugEnvDev,
		'test' => self::DebugEnvTest,
		'live' => self::DebugEnvLive,
	];

	private static $level_labels = [
		self::DebugErr    => 'ERROR ',
		self::DebugWarn   => 'WARN  ',
		self::DebugNotice => 'NOTICE',
		self::DebugInfo   => 'INFO  ',
		self::DebugTrace  => 'TRACE ',
	];

	private static $send_emails_from = self::DefaultSendEmailsFrom;

	private static $send_emails_to = self::DefaultSendEmailsTo;

	private $safe_paths = [];

	// where are messages coming from?
	private $source;

	// keep a stack of sources for e.g. enter/exit methods
	private $sources = [];

	/** @var Logger */
	private $logger;

	// what level will we trigger at
	private $level;

	// only send cookies for requests starting with this path on server
	private static $debug_request_path;
	// name of request variable (_GET) to trigger
	private static $debug_request_param;
	// value to check on request variable, if empty any truthish value will do, falsish will unset
	private static $debug_request_value;
	// name of cookie to set
	private static $debug_cookie_name;
	// cookie value to set
	private static $debug_cookie_value;

	public function __construct( $level = self::LevelFromEnv, $source = '' ) {
		parent::__construct();
		$this->init( $level, $source );
		$this->info( "Start of logging at " . date( 'Y-m-d H:i:s' ) );
	}

	/**
	 * If emailLogFileTo and logFilePathName is set then email the logFilePathName content if not empty
	 */
	public function __destruct() {
		if ( $this->emailLogFileTo && $this->logFilePathName ) {
			$this->info( "End of logging at " . date( 'Y-m-d H:i:s' ) );

			if ( $body = file_get_contents( $this->logFilePathName ) ) {
				$email = new \Email(
					static::send_emails_to(),
					$this->emailLogFileTo,
					'Debug log from: ' . \Director::protocolAndHost(),
					$body
				);
				$email->sendPlain();
			}
		}
	}

	/**
	 * configure debug cookie depending on config and request variables
	 *
	 * @param string       $matchPath to enable debugging for (request path, e.g. '/admin')
	 * @param string       $paramName name of getvar to check if we should send cookie, if empty config variable will be used
	 * @param array|string $envs      debug in these environments
	 */
	public static function cookies( $matchPath = '/', $paramName = '', $envs = [ 'dev' ] ) {
		$envs = is_array( $envs ) ? $envs : [ $envs ];

		$cookieName = \Config::inst()->get( self::class, 'debug_cookie_name' );

		if ( $cookieName && in_array( Director::get_environment_type(), $envs ) ) {
			$matchPath   = '/' . ltrim( $matchPath ?: \Config::inst()->get( self::class, 'debug_request_path' ), '/' );
			$requestPath = '/' . ltrim( $_SERVER['REQUEST_URI'], '/' );

			if ( $matchPath && ( substr( $requestPath, 0, strlen( $matchPath ) ) == $matchPath ) ) {
				$paramName   = $paramName ?: \Config::inst()->get( self::class, 'debug_request_param' );
				$paramValue  = \Config::inst()->get( self::class, 'debug_request_value' );
				$cookieValue = \Config::inst()->get( self::class, 'debug_cookie_value' );

				if ( $cookieName && $cookieValue && $paramName && array_key_exists( $paramName, $_GET ) ) {
					$requestParamValue = $_GET[ $paramName ];

					if ( ( ! $paramValue && $requestParamValue ) || ( $paramValue && ($requestParamValue == $paramValue )) ) {
						Cookie::set( $cookieName, $cookieValue, 1, $matchPath );
					} else {
						Cookie::set( $cookieName, null );
					}
				}
			}
		}
	}

	/**
	 * Set levels and source and if flags indicate debugging to file screen or email initialise those aspects of debugging using defaults from config.
	 *
	 * @param        $level
	 * @param string $source
	 *
	 * @param bool   $clearWriters
	 *
	 * @return $this
	 * @throws \Modular\Exceptions\Exception
	 * @throws \Zend_Log_Exception
	 */
	protected function init( $level, $source = null, $clearWriters = true ) {
		if ( $clearWriters ) {
			$this->logger()->clearWriters();
		}

		$level = $this->level( $level )->level();
		$this->source( $source ?: get_called_class() );

		if ( method_exists( $this, 'toFile' ) && $this->testbits( $level, self::DebugFile ) ) {
			$this->toFile( $level );
		}
		if ( method_exists( $this, 'toScreen' ) && $this->testbits( $level, self::DebugScreen ) ) {
			$this->toScreen( $level );
		}
		if ( method_exists( $this, 'toEmail' ) && $this->testbits( $level, self::DebugEmail ) ) {
			if ( $email = static::log_email() ) {
				static::toEmail( $email, $level );
			}
		}

		return $this;
	}

	/**
	 * Return instance of a logger to log things to.
	 *
	 * @return \Modular\Logger
	 */
	public function logger() {
		if ( ! $this->logger ) {
			$this->logger = new Logger();
		}

		return $this->logger;
	}

	/**
	 * Return instance of a debugger class to do debugging calls on (including logging).
	 *
	 * @param int|null $level
	 * @param string   $source
	 *
	 * @return mixed
	 */
	public static function debugger( $level = self::LevelFromEnv, $source = '' ) {
		$class = get_called_class();

		return new $class( $level, $source );
	}

	/**
	 * Setup level from passed level which may be to read from the environment.
	 *
	 * @inheritdoc
	 */
	public function level( $level = self::LevelFromEnv ) {
		if ( func_num_args() ) {
			if ( $this->testbits( $level, self::LevelFromEnv ) ) {
				$this->level = $this->env();
			} else {
				$this->level = $level;
			}

			return $this;
		} else {
			return $this->level;
		}
	}

	/**
	 * Set the source which will appear in output, also pushes the source onto
	 * a stack so can be popSource'd later.
	 *
	 * @param string $source
	 *
	 * @return $this|string
	 */
	public function source( $source = null ) {
		if ( func_num_args() ) {
			array_push( $this->sources, $this->source );
			$this->source = $source;

			return $this;
		} else {
			return $this->source;
		}
	}

	/**
	 * Pop back the last saved source, e.g. when exiting a method call where soource(__METHOD__) was called at the start.
	 *
	 * @return mixed
	 */
	public function popSource() {
		return array_pop( $this->sources );
	}

	/**
	 * Return the level for a given environment.
	 *
	 * @param string $env 'dev', 'test', 'live'
	 *
	 * @return $this
	 * @fluent
	 */
	public function env( $env = self::CurrentEnvironment ) {
		return $this->config()->get( 'environment_levels' )[ $env ];
	}

	/**
	 *
	 * @param string $message
	 * @param string $severity e.g. 'ERR', 'TRC'
	 * @param string $source
	 *
	 * @return mixed
	 */
	public function formatMessage( $message, $severity, $source = '' ) {
		$source = $source ?: ( $this->source() ?: get_called_class() );

		return implode( "\t", [
				date( 'Y-m-d' ),
				date( 'H:i:s' ),
				"$severity:",
				$source,
				static::digest( $message, $source ),
			] ) . ( \Director::is_cli() ? '' : '<br/>' ) . PHP_EOL;
	}

	/**
	 * Return level if level from facilities less than current level otherwise false.
	 *
	 * @param $facilities
	 *
	 * @return bool|int
	 */
	protected function lvl( $facilities, $compareToLevel = null ) {
		// strip out non-level facilities
		$level          = $facilities & ( self::DebugErr | self::DebugWarn | self::DebugNotice | self::DebugInfo | self::DebugTrace );
		$compareToLevel = is_null( $compareToLevel ) ? $this->level() : $compareToLevel;

		return $level <= $compareToLevel ? $level : false;
	}

	/**
	 *
	 * @param string $message either message or a language file key
	 * @param int    $facilities
	 * @param string $source
	 * @param array  $tokens  to replace in message
	 *
	 * @return $this
	 * @throws \Modular\Exceptions\Debug
	 */
	public function log( $message, $facilities, $source = '', $tokens = [] ) {
		$source = $source ?: ( $this->source() ?: get_called_class() );

		$message = static::digest( $message, $source, $tokens );

		if ( $level = $this->lvl( $facilities ) ) {
			$this->logger()->log( ( $source ? "$source: " : '' ) . $message . PHP_EOL, $level );
		}

		return $this;
	}

	/**
	 * Try to look up message in lang files by message and source as keys (max 20 characters, camelcased and spaces removed) or just return the message.
	 *
	 * @param string $message
	 * @param array  $source
	 * @param array  $tokens to replace in message
	 *
	 * @return string
	 */
	public static function digest( $message, $source, $tokens = [] ) {
		$key    = str_replace( ' ', '', ucwords( substr( $message, 0, 20 ) ) );
		$source = str_replace( ' ', '', ucwords( substr( $source, 0, 20 ) ) );

		return _t( "$source.$key", _t( $key, $message, $tokens ), $tokens );
	}

	/**
	 * @param string $message or a lang file key
	 * @param string $source
	 * @param array  $tokens  to replace in message
	 *
	 * @return $this
	 * @throws \Modular\Exceptions\Debug
	 */
	public function info( $message, $source = '', $tokens = [] ) {
		$this->log( $message, self::DebugInfo, $source, $tokens );

		return $this;
	}

	/**
	 * @param        $message
	 * @param string $source
	 * @param array  $tokens
	 *
	 * @return $this
	 * @throws \Modular\Exceptions\Debug
	 */
	public function trace( $message, $source = '', $tokens = [] ) {
		$this->log( $message, self::DebugTrace, $source, $tokens );

		return $this;
	}

	public function notice( $message, $source = '', $tokens = [] ) {
		$this->log( $message, self::DebugNotice, $source, $tokens );

		return $this;
	}

	public function warn( $message, $source = '', $tokens = [] ) {
		$this->log( $message, self::DebugWarn, $source, $tokens );

		return $this;
	}

	public function error( $message, $source = '', $tokens = [] ) {
		if ( \Director::isDev() ) {
			$this->fail( $message, $source );
		} else {
			$this->log( $message, self::DebugErr, $source, $tokens );
		}

		return $this;
	}

	/**
	 * @param        $messageOrException
	 * @param string $source
	 * @param array  $tokens
	 *
	 * @return $this
	 * @throws \Exception
	 * @throws \Modular\Exceptions\Debug
	 */
	public function fail( $messageOrException, $source = '', $tokens = [] ) {
		if ( $messageOrException instanceof \Exception ) {
			$message = $messageOrException->getMessage();

			$this->log( $message, self::DebugErr, $source, [
				'file'      => $messageOrException->getFile(),
				'line'      => $messageOrException->getLine(),
				'code'      => $messageOrException->getCode(),
				'backtrace' => $messageOrException->getTraceAsString(),
			] );
			throw $messageOrException;
		}
		$this->log( $messageOrException, self::DebugErr, $source );

		return $this;
	}

	/**
	 * Sets an error handler which will throw an exception of the same class as the passed exception
	 * or just base \Exception if null.
	 *
	 * @param string     $message will receive the error message
	 * @param mixed      $code    will receive the error code
	 * @param \Exception $exception
	 *
	 * @return callable the previous error handler
	 */
	public static function set_error_exception( &$message = '', &$code = '', \Exception $exception = null ) {
		return set_error_handler(
			function ( $errorCode, $errorMessage ) use ( &$message, &$code, $exception ) {
				$exceptionClass = $exception ? get_class( $exception ) : \Exception::class;
				$message        = $errorMessage;
				$code           = $errorCode;
				throw new $exceptionClass( $message, $code, $exception ?: null );
			}
		);
	}

	public static function send_emails_to() {
		return static::config()->get( 'send_emails_to' ) ?: Application::system_admin_email();
	}

	public static function log_email() {
		return static::config()->get( 'log_email' );
	}
}
