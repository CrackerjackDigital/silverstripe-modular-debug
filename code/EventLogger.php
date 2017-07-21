<?php

namespace Modular;

use Modular\Fields\EventSource;
use Modular\Fields\TextContent;
use Modular\Models\DebuggerEvent;
use SS_LogErrorFileFormatter;
use Zend_Log_FactoryInterface;
use Zend_Log_Writer_Abstract;

class EventLogger extends Zend_Log_Writer_Abstract {
	protected $messageType;

	protected $source;

	public function __construct( $messageType = 3, $source = '' ) {
		$this->messageType = $messageType;
		$this->source      = $source;
	}

	/**
	 * Construct a Zend_Log driver
	 *
	 * @param  int $messageType
	 *
	 * @return Zend_Log_FactoryInterface
	 */
	static public function factory( $messageType = 3 ) {
		return new self( $messageType );
	}

	public function _write( $event ) {
		if ( ! $this->_formatter ) {
			$formatter = new SS_LogErrorFileFormatter();
			$this->setFormatter( $formatter );
		}
		$message = $this->_formatter->format( $event );
		$event   = new DebuggerEvent( [
			TextContent::Name => $message,
			EventSource::Name => isset( $event['source'] ) ? $event['source'] : $this->source,
		] );
		$event->write();
	}
}