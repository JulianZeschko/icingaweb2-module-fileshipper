<?php

namespace Icinga\Module\Fileshipper;

use Icinga\Application\ApplicationBootstrap;

class VendorLoader
{
    public static function delegateLoadingToIcingaWeb(ApplicationBootstrap $app)
    {
		$app->getLoader()->registerNamespace(
            'phpseclib',
			implode(DIRECTORY_SEPARATOR, array(__DIR__, '..', 'vendor', 'phpseclib', 'phpseclib'))
        );
    }
}
