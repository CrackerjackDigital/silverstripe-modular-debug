<?php

namespace Modular\Traits;

use Modular\Application;
use Modular\Exceptions\Exception;
use Modular\Interfaces\Debugger;
use Modular\Logger;
use Modular\Module;
use SS_LogFileWriter;

trait logging_file {

	// prefix log file names with the date in format self.LogFilePrefixDateFormat
	private static $log_file_prefix_date = true;

	// name of log file to create if none supplied to toFile
	private static $log_file_name = 'silverstripe.log';

	// path to create log file in relative to base folder
	private static $log_file_path = ASSETS_PATH;

	/** @var array add class names to here to have logging from these classes have their own log files, named for the class */
	private static $class_own_logs = [];

	// set when toFile is called.
	private $logFilePathName;

	/**
	 * @param null $forClass
	 *
	 * @return \Config_ForClass
	 */
	abstract public function config( $forClass = null );

	/**
	 * @return Logger
	 */
	abstract public function logger();

	abstract public function source();

	/**
	 * Log to provided file name or to a configured file name. Filename is relative to site root if it starts with a '/' otherwise is interpreted as relative
	 * to assets folder. Checks to make sure final log file path is inside the web root.
	 *
	 * @param int    $level only log to file if event is below this level
	 * @param string $fileName
	 *
	 * @return $this
	 * @throws \Modular\Exceptions\Exception
	 *
	 */
	public function toFile( $level, $fileName = '' ) {
		if (!$fileName && ($source = $this->source())) {
			if ($classOwnLogs = $this->config()->get('class_own_logs') ?: []) {
				if ( is_numeric(key($classOwnLogs)) && in_array( $source, $classOwnLogs ) ) {
					// numeric index array, value is class name, fabricate a log file name
					$fileName = str_replace( '\\', '', $source ) . '.log';
				} elseif (array_key_exists( $source, $classOwnLogs)) {
					// map, key is class name, value is file name
					$fileName = $classOwnLogs[$source];
				}

			}
		}
		$this->logFilePathName = static::log_file_path_name( $fileName );

		// if truncate is specified then do so on the log file
		if ( $this->testbits($level, Debugger::DebugTruncate )) {
			if ( file_exists( $this->logFilePathName ) ) {
				unlink( $this->logFilePathName );
			}
		}

		$this->logger()->addWriter(
			new SS_LogFileWriter( $this->logFilePathName ),
			$this->lvl( $level ),
			"<="
		);
		$this->info( "Start of logging at " . date( 'Y-m-d h:i:s' ) );

		return $this;
	}

	/**
	 * Return line by line content of log file as a generator can be iterated over.
	 *
	 * @return \Generator|null
	 */
	public function readLog() {
		if ( $this->logFilePathName ) {
			if ( $fp = fopen( $this->logFilePathName, 'r' ) ) {
				while ( ! feof( $fp ) ) {
					yield fgets( $fp );
				}
				fclose( $fp );
			}
		}
	}

	/**
	 * Return log path from config.log_path and config.log_file or return something sensible. This will
	 * not be checked to make sure it is a 'safe' path, see safe_paths trait if you want to do this.
	 *
	 * @param string $extension
	 * @param string $useFileName
	 * @param string $usePath
	 *
	 * @return string
	 * @throws \Modular\Exceptions\Exception
	 */
	public static function log_file_path_name( $useFileName = '', $usePath = '', $extension = '.log' ) {
		$path     = $usePath ?: static::log_file_path();
		$fileName = $useFileName ?: static::log_file_name();

		if ( $extension && ( substr( $fileName, - strlen( $extension ) ) != $extension ) ) {
			$fileName .= $extension;
		}

		return $path . DIRECTORY_SEPARATOR . $fileName;
	}

	/**
	 * Return a directory to put logs in from config.log_path or figure out a safe one
	 * in assets directory. If in assets and doesn't exist already will create it.
	 *
	 * @return string
	 * @throws \Modular\Exceptions\Exception
	 */
	public static function log_file_path() {
		$path = static::config()->get( 'log_file_path' );
		if ( ! $path ) {
			if ( defined( 'SS_ERROR_LOG' ) ) {
				$path = dirname( SS_ERROR_LOG );
			}
		}
		if ( substr( $path, 0, 1 ) == '/' || substr( $path, 0, 2 ) == '..' ) {
			// relative to docroot, make absolute from filesystem root to directory,
			// must already exist (realpath returns false if it doesn't)

			$path = realpath( BASE_PATH . DIRECTORY_SEPARATOR . trim( $path, '/' ) );
			if ( false === $path ) {
				$path = ASSETS_PATH;
			}
		} else {
			// relative to assets, make absolute from filesystem root to directory in assets,
			// create it doesn't exist
			$path = ASSETS_PATH . DIRECTORY_SEPARATOR . $path;
			if ( ! is_dir( $path ) ) {
				\Filesystem::makeFolder( $path );
			}

		}

		return realpath( $path );
	}

	/**
	 * Return a filename without a path to use for logging from the supplied class or module's.
	 */
	public static function log_file_name() {
		$fileName = static::config()->get( 'log_file_name' );

		if ( ! $fileName ) {
			if ( defined( 'SS_ERROR_LOG' ) ) {
				$fileName = basename( SS_ERROR_LOG );
			} else {
				$fileName = 'silverstripe.log';
			}
		}
		if ( static::config()->get( 'log_file_prefix_date' ) ) {
			$fileName = date( Logger::LogFilePrefixDateFormat );
		}

		return $fileName;
	}

}