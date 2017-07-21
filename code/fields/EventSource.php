<?php
namespace Modular\Fields;

/**
 * EventSource configure using config.options in your app for valid sources. Modules and apps should be able to add them independently via config.
 *
 * @package Modular\Fields
 */
class EventSource extends Enum {
	private static $options = [
		'Global'
	];
}