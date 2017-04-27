<?php
namespace Modular\Traits;

use Modular\Debugger;
use Modular\Logger;

trait logging_screen {

	/**
	 * @return Logger
	 */
	abstract public function logger();

	/**
	 * @param int|null $level
	 *
	 * @return $this
	 * @throws \Zend_Log_Exception
	 */
	public function toScreen( $level = Debugger::LevelFromEnv ) {
		if ( is_null( $level ) || $level === Debugger::LevelFromEnv ) {
			$level = $this->config()->get( 'environment_levels' )[ Debugger::EnvType ];
		}
		$this->logger()->addWriter( new \LogOutputWriter( $level ) );

		return $this;
	}

}