<?php
class EventAdmin extends ModelAdmin {
	private static $managed_models = ['Modular\Models\DebuggerEvent'];

	private static $url_segment = 'debuggger-events';

	private static $menu_title = 'Debug Events';

	private static $allowed_actions = [
		'SearchForm'
	];

}