<?php

use SilverStripe\Core\Object;
class i18nOtherModule extends Object {
	public function mymethod() {
		_t(
			'i18nOtherModule.ENTITY',
			'Other Module Entity'
		);
	}
}
